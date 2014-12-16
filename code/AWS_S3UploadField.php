<?php
/**
 * Created by PhpStorm.
 * User: rainerspittel
 * Date: 16/12/14
 * Time: 1:30 PM
 */

class AWS_S3UploadField extends UploadField
{

    protected $bucketName;

    public function setBucketName($bucketName)
    {
        $this->bucketName = $bucketName;
        return $this;
    }

    public function getBucketName()
    {
        return ($this->bucketName !== false) ? $this->bucketName : Config::inst()->get('AWS_S3File','default_bucket');
    }


    public function setFolderName($bucketName)
    {
        $this->setBucketName($bucketName);
        return $this;
    }

    public function getFolderName()
    {
        return $this->getBucketName();
    }

    public function __construct($name, $title = null, $value = null)
    {
        parent::__construct($name, $title, $value);

        $this->upload = AWS_S3Upload::create();
    }

    protected function saveTemporaryFile($tmpFile, &$error = null)
    {
        // Determine container object
        $error = null;

        $fileObject = null;

        if (empty($tmpFile))
        {
            $error = _t('UploadField.FIELDNOTSET', 'File information not found');
            return null;
        }

        if($tmpFile['error'])
        {
            $error = $tmpFile['error'];
            return null;
        }

        // Search for relations that can hold the uploaded files, but don't fallback
        // to default if there is no automatic relation
        if ($relationClass = $this->getRelationAutosetClass(null))
        {
            // Create new object explicitly. Otherwise rely on Upload::load to choose the class.
            $fileObject = Object::create($relationClass);
        }

        // Allow replacing files (rather than renaming a duplicate) when warning about overwrites
        if($this->getConfig('overwriteWarning'))
        {
            $this->upload->setReplaceFile(true);
        }

        // Get the uploaded file into a new file object.
        try {

            $this->upload->loadIntoFile($tmpFile, $fileObject, $this->getBucketName());

        } catch (Exception $e) {
            // we shouldn't get an error here, but just in case
            $error = $e->getMessage();
            return null;
        }

        // Check if upload field has an error
        if ($this->upload->isError()) {
            $error = implode(' ' . PHP_EOL, $this->upload->getErrors());
            return null;
        }

        // return file
        return $this->upload->getFile();
    }

} 