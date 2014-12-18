<?php
/**
 * Created by PhpStorm.
 * User: rainerspittel
 * Date: 16/12/14
 * Time: 2:50 PM
 */

use Aws\Common\Credentials\Credentials;
use Aws\Common\Aws;

class AWS_S3Upload extends Upload
{

    protected $s3Client = null;

    /**
     * Returns and if required initiates a AWS S3 Client with the credentials provided by the project configuration.
     * Reads access_key, secret_key and region fron the AWS_S3File config.
     *
     * @return S3Client (@see Aws\S3\S3Client)
     */
    private function getAWSS3Client()
    {
        if (!$this->s3Client)
        {
            $credentials = new Credentials(
                Config::inst()->get('AWS_S3File','access_key'),
                Config::inst()->get('AWS_S3File','secret_key')
            );

            $aws = Aws::factory(array(
                'credentials' => $credentials,
                'region' => Config::inst()->get('AWS_S3File','region')
            ));

            // create s3 client object.
            $this->s3Client =  $aws->get('s3');
        }

        return $this->s3Client;
    }

    /**
     * This method updates the tmpFile to a AWS S3 Bucket.
     *
     * The credentials need to be configured by the config environment.
     *
     * @param $tmpFile string to file to be updated, in general the tmp file based on the form file upload.
     * @param $fileName key, destination file name. The filename needs to be unique in the bucket
     * @param $bucket AWS_S3Bucket instance. The instance of the bucket this file will be loaded up to.
     *
     * @return bool true if successful, otherwise false. See $this->errors for error description.
     *
     */
    private function uploadFile($bucket, $fileName, $tmpFile )
    {
        if(!file_exists($tmpFile['tmp_name']))
        {
            $this->errors[] = _t('File.NOFILESIZE', 'Filesize is zero bytes.');
            return false;
        }

        $bucketName = $bucket->Name;
        $s3Client   = $this->getAWSS3Client();

        //
        // if replace-option is not set but the file does already exist on the AWS S3 storage, then throw an error
        if (!$this->replaceFile)
        {
            if ($this->doesObjectExist($bucket->Name, $fileName))
            {
                $this->errors[] = _t('AWS_S3File.OVERWRITENOTPERMITTED', "Could not upload file {filename} as overwrite was not granted.",
                    array(
                        'filename' => $fileName
                    )
                );
                return false;
            }
        }

        $s3Client->upload(
            $bucketName,
            $fileName,
            fopen($tmpFile['tmp_name'], 'r+')
        );

        $this->file->ParentID = $bucket ? $bucket->ID : 0;

        //
        // This is to prevent it from trying to rename the file
        $this->file->Name         = $fileName;
        $this->file->Bucket       = $bucketName;
        $this->file->URL          = "http://{$bucketName}.s3.amazonaws.com/{$fileName}";
        $this->file->OriginalName = $tmpFile['name'];
        $this->file->ParentID     = $bucket->ID;
        $this->file->OwnerID      = (Member::currentUser() ? Member::currentUser()->ID : 0);
        $this->file->write();

        $this->file->onAfterUpload();
        $this->extend('onAfterLoad', $this->file);  // to allow extensions to e.g. create a version after an upload

        return true;
    }

    /**
     * Save an file passed from a form post into this object.
     *
     * File names are filtered through {@link FileNameFilter}, see class documentation
     * on how to influence this behaviour.
     *
     * @param $tmpFile array Indexed array that PHP generated for every file it uploads.
     * @param $folderPath string Folder path relative to /assets
     *
     * @return Boolean|string Either success or error-message.
     */
    public function load($tmpFile, $folderPath = false)
    {
        $this->clearErrors();

        if(!$folderPath)
        {
            $folderPath = Config::inst()->get('AWS_S3File','default_bucket');
        }

        if(!is_array($tmpFile))
        {
            user_error("Upload::load() Not passed an array.  Most likely, the form hasn't got the right enctype",
                E_USER_ERROR);
        }

        if(!$tmpFile['size'])
        {
            $this->errors[] = _t('File.NOFILESIZE', 'Filesize is zero bytes.');
            return false;
        }

        $valid = $this->validate($tmpFile);
        if(!$valid)
        {
            return false;
        }

        // get bucket object, used to associate the objects with a bucket.
        $parentBucket = AWS_S3Bucket::find_or_make($folderPath);

        // Generate default filename
        $nameFilter = FileNameFilter::create();
        $file       = $nameFilter->filter($tmpFile['name']);
        $fileName   = basename($file);

        $uploadFilename = $fileName;

        //
        // If $this->file is null, it implies that the file updated can not be linked automatically to a relationship
        if(!$this->file)
        {
            $this->file = new AWS_S3File();
        }

        //
        // if file object (AWS_S3File instance) is not set in this object, get the object from the database.
        if (!$this->file->ID && $this->replaceFile)
        {
            $fileClass = $this->file->class;

            $file = DataList::create($fileClass)->filter(
                array(
                    'ClassName' => $fileClass,
                    'Name' => $fileName,
                    'ParentID' => $parentBucket ? $parentBucket->ID : 0,
                    'OriginalName' => $tmpFile['name']
                )
            )->First();

            if ($file)
            {
                $this->file = $file;
            }
        }

        // if filename already exists, version the filename (e.g. test.gif to test2.gif, test2.gif to test3.gif)
        if(!$this->replaceFile)
        {
            $fileSuffixArray = explode('.', $fileName);
            $fileTitle       = array_shift($fileSuffixArray);
            $fileSuffix      = !empty($fileSuffixArray)
                ? '.' . implode('.', $fileSuffixArray)
                : null;

            $uploadFilename = $fileTitle . $fileSuffix;
            while($this->doesObjectExist($parentBucket->Name, $uploadFilename))
            {
                $i = isset($i) ? ($i+1) : 2;
                $oldFilePath = $uploadFilename;

                $pattern = '/([0-9]+$)/';
                if(preg_match($pattern, $fileTitle)) {
                    $fileTitle = preg_replace($pattern, $i, $fileTitle);
                } else {
                    $fileTitle .= $i;
                }
                $uploadFilename = $fileTitle . $fileSuffix;

                if($oldFilePath == $uploadFilename && $i > 2)
                {
                    $this->errors[] = _t('AWS_S3File.CREATEUNIQUEFILENAME', "Filename {filename} does exist on storage. Tried to rename the file {count} times but still could not resolve the conflict. Please rename the file and try again.",
                        array(
                        'filename' => $file,
                        'count' => $i
                        )
                    );
                    return false;
                }

                // stop after 10 attempts and wrote error
                if ($i > 9)
                {
                    $this->errors[] = _t('AWS_S3File.CREATEUNIQUEFILENAME', "Filename {filename} does exists on storage. Tried to rename the file {count} times but still could not resolve the conflict. Please rename the file and try again.",
                        array(
                            'filename' => $file,
                            'count' => $i
                        )
                    );

                    return false;
                }
            }
        }
        return $this->uploadFile($parentBucket, $uploadFilename, $tmpFile);
    }


    /**
     * Returns true if the provided filename does exist in the bucket.
     *
     * @param $bucket
     * @param $filename
     * @return bool
     */
    public function doesObjectExist($bucket, $filename)
    {
        $s3     = $this->getAWSS3Client();
        $result = $s3->doesObjectExist($bucket,$filename);
        return $result;
    }

    /**
     * This method deletes a remote file from a bucket. The object to be deleted is passed in as a AWS_S3File data
     * object.
     *
     * Error messages are stored in $this->errors;
     *
     * @param $s3file AWS_S3File
     *
     * @return bool true if delete was successful.
     */
    public function deleteRemoteObject($s3file)
    {
        $s3 = $this->getAWSS3Client();
        try
        {
            $s3->deleteObject(array(
                'Bucket' => $s3file->Bucket,
                'Key' => $s3file->Name
            ));

            // The array $result does not clearly state if the deletion was successful.
            // That's why I'll check if the file still exists.
            $exists = $this->doesObjectExist($s3file->Bucket,$s3file->Name);
            if ($exists)
            {
                $this->errors[] = _t('AWS_S3File.DELETEFAILED', 'File has not been deleted successfully from storage.');
                return false;
            }
        }

        catch(Exception $e)
        {
            $this->errors[] = $e->getMessage();
            return false;
        }
        return true;
    }

} 