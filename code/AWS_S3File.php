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
        "Name"         => "Varchar(255)",
        "Bucket"       => "Varchar(255)",
        "URL"          => "Varchar(1024)",
        "OriginalName" => "Varchar(255)"
    );

    static $has_one = array(
        "Parent" => "AWS_S3Bucket",
        "Owner"  => "Member"
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

    /**
     * Wrapper to get extension of the filename only.
     *
     * @return string
     */
    public function getExtension()
    {
        return File::get_file_extension($this->getField('Name'));
    }

    /**
     * Returns path to the icon image.
     *
     * If checks if the extension is defined in the module and use specific icons and fails
     * over to framework if no image do exist.
     *
     * @return string
     */
    public function Icon()
    {
        $path = Config::inst()->get('AWS_S3File','icons_path');

        $ext = strtolower($this->getExtension());

        //
        // Find an icon symbol in the defined app-icon folder. If not available fail over to
        // framework icons. If that fails, too, use the generic icon from framework.
        if(!Director::fileExists($path."/{$ext}_32.gif"))
        {
            $ext = File::get_app_category($this->getExtension());
        }
        if (Director::fileExists($path."{$ext}_32.gif"))
        {
            return $path."{$ext}_32.gif";
        }

        //
        // taken from File.Icon()
        $ext = strtolower($this->getExtension());

        if(!Director::fileExists(FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif")) {
            $ext = File::get_app_category($this->getExtension());
        }

        if(!Director::fileExists(FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif")) {
            $ext = "generic";
        }

        return FRAMEWORK_DIR . "/images/app_icons/{$ext}_32.gif";
    }

    public function downloadFile(Controller $controller)
    {
        $s3 = AWS_S3Upload::create();

        $result = $s3->downloadObject($this);

        if ($result && isset($result['Body']))
        {
            $response = $controller->getResponse();
            $response->addHeader('Content-Type',HTTP::get_mime_type($this->Name));
            $response->addHeader('Content-Description','File Transfer');
            $response->addHeader('Content-Disposition','attachment; filename="'.$this->Name.'"');
            $response->addHeader('Content-Length',$result['ContentLength']);

            $response->setBody($result['Body']);
            return $response;

        }
        return null;
    }
}

