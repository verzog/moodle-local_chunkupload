<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

/**
 * Strings for plugin 'local_chunkupload'
 *
 * @package   local_chunkupload
 * @copyright 2020 Justus Dieckmann WWU
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['cleanup_task'] = 'Task to clean up old tokens and files';
$string['deletefile'] = 'Delete file from moodle';
$string['maxsize'] = 'Maximum file size: {$a}';
$string['pluginname'] = 'Chunk upload';
$string['privacy:metadata:local_chunkupload_files'] = 'Temporary records of files uploaded in chunks before final save.';
$string['privacy:metadata:local_chunkupload_files:contextid'] = 'The context in which the file was uploaded.';
$string['privacy:metadata:local_chunkupload_files:filename'] = 'The name of the uploaded file.';
$string['privacy:metadata:local_chunkupload_files:lastmodified'] = 'The time the upload was last modified.';
$string['privacy:metadata:local_chunkupload_files:userid'] = 'The ID of the user who uploaded the file.';
$string['setting:chunksize'] = 'Chunk size (MB)';
$string['setting:state0duration'] = 'Duration until unused token is deleted';
$string['setting:state1duration'] = 'Duration until uncompleted file upload is deleted';
$string['setting:state2duration'] = 'Duration until completed file upload is deleted';
$string['tokenexpired'] = 'The upload token has expired. Try refreshing the page to receive a new one.';
$string['uploaded'] = 'File uploaded';
$string['uploadnotfinished'] = 'Upload did not finish!';
