<?php
/**
 * Created by PhpStorm.
 * User: rainerspittel
 * Date: 16/12/14
 * Time: 3:54 PM
 */

class AWS_S3Bucket extends DataObject
{

    private static $db = array(
        "Name" => "Varchar"
    );

    /**
     * This method checks if a given bucket name does exist as dataobject (AWS_S3Bucket) and if it doesn't create one.
     *
     * @param $bucketName
     *
     * @return DataObject
     */
    public static function find_or_make($bucketName)
    {
        $list = DataList::create('AWS_S3Bucket')->where("\"Name\" = '{$bucketName}'");

        if (!($bucket = $list->First()))
        {
            $bucket = AWS_S3Bucket::create();
            $bucket->Name = $bucketName;
            $bucket->write();
        }
        return $bucket;
    }
} 