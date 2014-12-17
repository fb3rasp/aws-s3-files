<?php
/**
 * Created by PhpStorm.
 * User: rainerspittel
 * Date: 16/12/14
 * Time: 1:28 PM
 */

class AWS_S3File extends DataObject
{

    static $db = array(
        "Name" => "Varchar(255)",
        "Bucket" => "Varchar(255)",
        "URL" => "Varchar(255)"
    );

    static $has_one = array(
        "Parent" => "AWS_S3Bucket",
        "Owner" => "Member"
    );

    /**
     * Set the two API keys needed to connect to S3
     *
     * @param string $access The access key
     * @param string $secret The secret key
     */
    public static function set_auth($access, $secret)
    {
        Config::inst()->update('AWS_S3File','access_key',$access);
        Config::inst()->update('AWS_S3File','secret_key',$secret);
    }

    /**
     * Globally sets the default bucket where all uploads should go
     *
     * @param string $bucket The name of the bucket
     */
    public static function set_default_bucket($bucket)
    {
        Config::inst()->update('AWS_S3File','default_bucket',$bucket);
    }

    public static function set_region($region)
    {
        Config::inst()->update('AWS_S3File','region',$region);
    }

    /**
     * Getter for the "Filename" field. This is stored as a field for File, but here
     * it is done dynamically.
     *
     * @return string
     */
    public function getFilename()
    {
        return basename($this->URL);
    }


    public function onAfterUpload()
    {
        $this->extend('onAfterUpload');
    }


    /**
     * Deletes the associated object from the remote storage.
     *
     * @reutrn bool true if deletion was successful
     */
    public function deleteRemoteObject()
    {
        $s3 = AWS_S3Upload::create();

        if ($this->Name && $s3->doesObjectExist($this->Bucket,$this->Name))
        {
            $s3->deleteRemoteObject($this);
            if ($s3->isError())
            {
                return false;
            }
        }
        return true;
    }
}

