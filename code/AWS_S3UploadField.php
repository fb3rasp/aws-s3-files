<?php
/**
 * Created by PhpStorm.
 * User: rainerspittel
 * Date: 16/12/14
 * Time: 1:30 PM
 */

class AWS_S3UploadField extends UploadField
{
    private static $allowed_actions = array(
        'upload'
    );

    protected $bucketName = false;

    /**
     * Sets the bucket name
     *
     * @param $bucketName
     *
     * @return $this
     */
    public function setBucketName($bucketName)
    {
        $this->bucketName = $bucketName;
        return $this;
    }

    /**
     * Gets the bucket name. If no name is defined, return the default bucket name out of the configuraiton.
     *
     * Uses AWS_S3File.default_bucket config as a fall back.
     *
     * @return string Name of the bucket
     */
    public function getBucketName()
    {
        return ($this->bucketName !== false) ? $this->bucketName : Config::inst()->get('AWS_S3File','default_bucket');
    }

    /**
     * Sets the folder name, same as setBucketName. This method is used by the parent class UploadField.
     *
     * @param string $bucketName
     * @return $this|FileField
     */
    public function setFolderName($bucketName)
    {
        $this->setBucketName($bucketName);
        return $this;
    }

    /**
     * Gets the folder name, same as setBucketName. This method is used by the parent class UploadField.
     *
     * @return String
     */
    public function getFolderName()
    {
        return $this->getBucketName();
    }

    /**
     * Creates new instance of the AWS_S3UploadField.
     *
     * It is a form field with overwrites some specific behaviour of its parent UploadField to enable SilverStripe to
     * update some files onto S3 Storage.
     *
     * @param string $name Field name
     * @param null $title Title of the field (label)
     * @param null $value
     */
    public function __construct($name, $title = null, $value = null)
    {
        parent::__construct($name, $title, $value);

        //
        // set new template names. We do not support the edit capabilities for files on a S3Storage at this stage.
        $this->setTemplate('AWS_S3UploadField');
        $this->setTemplateFileButtons('AWS_S3UploadField_FileButtons');

        // overwrites the instance of the upload controller class. Not nice but nessessary
        $this->upload = AWS_S3Upload::create();
    }

    /**
     * Check if file exists, both checking filtered filename and exact filename
     *
     * @param string $originalFile Filename
     * @return bool
     */
    protected function checkFileExists($originalFile)
    {
        // Check both original and safely filtered filename
        $nameFilter = FileNameFilter::create();
        $filteredFile = $nameFilter->filter($originalFile);

        // Resolve expected folder name
        $bucketName = $this->getBucketName();

        // check if either file exists
        $exitsOriginal = $this->upload->doesObjectExist($bucketName,$originalFile);
        $exitsFiltered = $this->upload->doesObjectExist($bucketName,$filteredFile);

        return  $exitsOriginal || $exitsFiltered;
    }

    /**
     * @param int $itemID
     * @return UploadField_ItemHandler
     */
    public function getItemHandler($itemID) {
        return AWS_S3UploadField_ItemHandler::create($this, $itemID);
    }

    /**
     * Customises a file with additional details suitable for rendering in the
     * UploadField.ss template
     *
     * @param File $file
     * @return ViewableData_Customised
     */
    protected function customiseFile(File $file)
    {
        $file = $file->customise(array(
            'UploadFieldDeleteLink' => $this->getItemHandler($file->ID)->DeleteLink(),
            'UploadField' => $this
        ));

        // we do this in a second customise to have the access to the previous customisations
        return $file->customise(array(
            'UploadFieldFileButtons' => (string)$file->renderWith($this->getTemplateFileButtons())
        ));
    }


    /**
     * Gets the foreign class that needs to be created, or 'File' as default if there
     * is no relationship, or it cannot be determined.
     *
     * @param $default Default value to return if no value could be calculated
     * @return string Foreign class name.
     */
    public function getRelationAutosetClass($default = 'AWS_S3File') {

        // Don't autodetermine relation if no relationship between parent record
        if(!$this->relationAutoSetting) return $default;

        // Check record and name
        $name = $this->getName();
        $record = $this->getRecord();
        if(empty($name) || empty($record)) {
            return $default;
        } else {
            $class = $record->getRelationClass($name);
            return empty($class) ? $default : $class;
        }
    }

    /**
     * Action to handle upload of a single file
     *
     * @param SS_HTTPRequest $request
     * @return SS_HTTPResponse
     * @return SS_HTTPResponse
     */
    public function upload(SS_HTTPRequest $request)
    {
        $response = parent::upload($request);


        /**
         * To show error messages in the upload form field, the error messages need to be translated into a string
         * and returned as part of the status description. General upload errors will cause a 403 status code. We use
         * this to identify if an error occurred, get the error encoded in the response body and convert it into a string.
         */
        if ($response->getStatusCode()==403)
        {
            $body = $response->getBody();
            $errors = json_decode($body, true);

            if (is_array($errors))
            {
                $errorMessage = $errors[0];

                if (isset($errorMessage['error']))
                {
                    $message = str_replace("\n",' ; ',$errorMessage['error']);
                    $response->setStatusDescription('Forbidden - '.$message);
                }
            }
        }
        return $response;
    }
}

/**
 * RequestHandler for actions (edit, remove, delete) on a single item (File) of the UploadField
 *
 * @author Zauberfisch
 * @package forms
 * @subpackages fields-files
 */
class AWS_S3UploadField_ItemHandler extends RequestHandler
{

    /**
     * @var UploadFIeld
     */
    protected $parent;

    /**
     * @var int FileID
     */
    protected $itemID;

    private static $url_handlers = array(
        '$Action!' => '$Action',
        '' => 'index',
    );

    private static $allowed_actions = array(
        'delete',
    );

    /**
     * @param AWS_S3UploadField $parent
     * @param int $item
     */
    public function __construct($parent, $itemID) {
        $this->parent = $parent;
        $this->itemID = $itemID;

        parent::__construct();
    }

    /**
     * @return File
     */
    public function getItem() {
        return DataObject::get_by_id('AWS_S3File', $this->itemID);
    }

    /**
     * @param string $action
     * @return string
     */
    public function Link($action = null) {
        return Controller::join_links($this->parent->Link(), '/item/', $this->itemID, $action);
    }

    /**
     * @return string
     */
    public function DeleteLink() {
        $token = $this->parent->getForm()->getSecurityToken();
        return $token->addToUrl($this->Link('delete'));
    }

    /**
     * Action to handle deleting of a single file
     *
     * @param SS_HTTPRequest $request
     * @return SS_HTTPResponse
     */
    public function delete(SS_HTTPRequest $request) {
        // Check form field state
        if($this->parent->isDisabled() || $this->parent->isReadonly()) return $this->httpError(403);

        // Protect against CSRF on destructive action
        $token = $this->parent->getForm()->getSecurityToken();
        if(!$token->checkRequest($request))
        {
            return $this->httpError(400);
        }

        // Check item permissions
        $item = $this->getItem();
        if(!$item) {
            return $this->httpError(404);
        }

        if(!$item->canDelete()) {
            return $this->httpError(403);
        }

        if ($item->deleteRemoteObject())
        {
            $item->delete();
        }
    }

}