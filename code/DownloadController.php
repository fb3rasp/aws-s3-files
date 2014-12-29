<?php

/**
 * Created by PhpStorm.
 * User: rainerspittel
 * Date: 18/12/14
 * Time: 3:22 PM
 */

/**
 * Class DownloadController
 *
 * Test controller classe
 */
class DownloadController extends Controller
{

    static private $allowed_actions = array(
        'download',
        'index'
    );

    public function init()
    {
        parent::init();
        if (!Director::isDev()) {
            user_error('Test controller only available in test dev-environment',E_USER_ERROR);
        }
    }

    public function index()
    {
        $list = DataList::create('AWS_S3File');

        echo "<h1>Available Files</h1>";
        echo "<ul>";
        foreach($list as $item)
        {
            echo sprintf("<li><a href='download/%s'>Download %s</a> - <a href='%s'>Direct Download</a></li>", $item->ID,$item->Name, $item->URL);

        }
        echo "</ul>";
    }

    public function download(SS_HTTPRequest $request)
    {
        $Id = $request->param('ID');
        $file = DataList::create('AWS_S3File')->filter(
            array(
                'ID' => (int)$Id
            )
        )->First();

        if ($file)
        {
            $response = $file->downloadFile($this);
            return $response;
        }
    }

} 