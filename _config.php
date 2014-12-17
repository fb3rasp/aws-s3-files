<?php


//
// configure s3 storage for file update/download
if (defined('S3FILE_ACCESS_KEY_ID'))
{
    AWS_S3File::set_auth(S3FILE_ACCESS_KEY_ID, S3FILE_SECRET_ACCESS_KEY);
    AWS_S3File::set_default_bucket(S3FILE_DEFAULTBUCKET);
    AWS_S3File::set_region(S3FILE_REGION);
}
