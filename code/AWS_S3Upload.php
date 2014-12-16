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

    /**
     * Save an file passed from a form post into this object.
     * File names are filtered through {@link FileNameFilter}, see class documentation
     * on how to influence this behaviour.
     *
     * @param $tmpFile array Indexed array that PHP generated for every file it uploads.
     * @param $folderPath string Folder path relative to /assets
     * @return Boolean|string Either success or error-message.
     */
    public function load($tmpFile, $folderPath = false) {
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

        $parentBucket = AWS_S3Bucket::find_or_make($folderPath);

        // Generate default filename
        $nameFilter = FileNameFilter::create();
        $file       = $nameFilter->filter($tmpFile['name']);
        $fileName   = basename($file);

        if (!$this->file->ID && $this->replaceFile)
        {
            $fileClass = $this->file->class;

            $file = DataList::create($fileClass)->filter(
                array(
                    'ClassName' => $fileClass,
                    'Name' => $fileName,
                    'ParentID' => $parentBucket ? $parentBucket->ID : 0
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

            /**  TODO CHECK IF FILE rEPLACES EXISTING FILE  */
//            $fileSuffixArray = explode('.', $fileName);
//            $fileTitle = array_shift($fileSuffixArray);
//            $fileSuffix = !empty($fileSuffixArray)
//                ? '.' . implode('.', $fileSuffixArray)
//                : null;
//
//            // make sure files retain valid extensions
//            $oldFilePath = $relativeFilePath;
//            $relativeFilePath = $relativeFolderPath . $fileTitle . $fileSuffix;
//            if($oldFilePath !== $relativeFilePath) {
//                user_error("Couldn't fix $relativeFilePath", E_USER_ERROR);
//            }
//            while(file_exists("$base/$relativeFilePath")) {
//                $i = isset($i) ? ($i+1) : 2;
//                $oldFilePath = $relativeFilePath;
//
//                $pattern = '/([0-9]+$)/';
//                if(preg_match($pattern, $fileTitle)) {
//                    $fileTitle = preg_replace($pattern, $i, $fileTitle);
//                } else {
//                    $fileTitle .= $i;
//                }
//                $relativeFilePath = $relativeFolderPath . $fileTitle . $fileSuffix;
//
//                if($oldFilePath == $relativeFilePath && $i > 2) {
//                    user_error("Couldn't fix $relativeFilePath with $i tries", E_USER_ERROR);
//                }
//            }
        }
        else
        {
            // Reset the ownerID to the current member when replacing files
            $this->file->OwnerID = (Member::currentUser() ? Member::currentUser()->ID : 0);
        }

        $credentials = new Credentials(
            Config::inst()->get('AWS_S3File','access_key'),
            Config::inst()->get('AWS_S3File','secret_key')
        );

        $bucket = Config::inst()->get('AWS_S3File','default_bucket');

        $aws = Aws::factory(array(
            'credentials' => $credentials,
            'region' => 'eu-central-1'
        ));
        $s3Client = $aws->get('s3');

        if(file_exists($tmpFile['tmp_name'])) {

            $result = $s3Client->upload(
                $bucket,
                $fileName,
                fopen($tmpFile['tmp_name'], 'r+')
            );

            $this->file->ParentID = $parentBucket ? $parentBucket->ID : 0;

            // This is to prevent it from trying to rename the file
            $this->file->Name = $fileName;
            $this->file->Bucket = $bucket;
            $this->file->URL = "http://{$bucket}.s3.amazonaws.com/{$fileName}";
            $this->file->write();

            $this->file->onAfterUpload();
            $this->extend('onAfterLoad', $this->file);   //to allow extensions to e.g. create a version after an upload
            return true;
        } else {
            $this->errors[] = _t('File.NOFILESIZE', 'Filesize is zero bytes.');
            return false;
        }
    }
} 