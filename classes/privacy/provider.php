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
 * Privacy provider for local_chunkupload.
 *
 * @package   local_chunkupload
 * @copyright 2026 Skin Cancer College Australasia
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_chunkupload\privacy;

use context;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_chunkupload\chunkupload_form_element;

/**
 * Privacy provider for local_chunkupload.
 *
 * The plugin stores a temporary record per upload in {local_chunkupload_files},
 * together with the file content on the filesystem, until the cleanup task
 * removes it. The record is tied to the uploading user and the context.
 *
 * @package   local_chunkupload
 * @copyright 2026 Skin Cancer College Australasia
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\core_userlist_provider,
    \core_privacy\local\request\plugin\provider {
    /**
     * Describe the personal data stored by this plugin.
     *
     * @param collection $collection The metadata collection to add to.
     * @return collection The updated collection.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'local_chunkupload_files',
            [
                'userid' => 'privacy:metadata:local_chunkupload_files:userid',
                'contextid' => 'privacy:metadata:local_chunkupload_files:contextid',
                'filename' => 'privacy:metadata:local_chunkupload_files:filename',
                'lastmodified' => 'privacy:metadata:local_chunkupload_files:lastmodified',
            ],
            'privacy:metadata:local_chunkupload_files'
        );
        return $collection;
    }

    /**
     * Get the list of contexts that contain data for the given user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contexts containing data for the user.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();
        $sql = "SELECT DISTINCT contextid
                  FROM {local_chunkupload_files}
                 WHERE userid = :userid AND contextid IS NOT NULL";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);
        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist to add the users to.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        $sql = "SELECT userid
                  FROM {local_chunkupload_files}
                 WHERE contextid = :contextid AND userid IS NOT NULL";
        $userlist->add_from_sql('userid', $sql, ['contextid' => $context->id]);
    }

    /**
     * Export all user data for the given approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export data for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $user = $contextlist->get_user();
        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records(
                'local_chunkupload_files',
                ['userid' => $user->id, 'contextid' => $context->id]
            );
            if (!$records) {
                continue;
            }
            $files = [];
            foreach ($records as $record) {
                $files[] = (object) [
                    'filename' => $record->filename,
                    'state' => $record->state,
                    'lastmodified' => $record->lastmodified ?
                        transform::datetime($record->lastmodified) : null,
                ];
            }
            writer::with_context($context)->export_data(
                [get_string('pluginname', 'local_chunkupload')],
                (object) ['files' => $files]
            );
        }
    }

    /**
     * Delete all data for all users in the given context.
     *
     * @param context $context The context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(context $context) {
        global $DB;
        $ids = $DB->get_fieldset_select(
            'local_chunkupload_files',
            'id',
            'contextid = :contextid',
            ['contextid' => $context->id]
        );
        self::delete_files_for_ids($ids);
    }

    /**
     * Delete all data for the user in the given approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to delete data for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $contextids = $contextlist->get_contextids();
        if (!$contextids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
        $params['userid'] = $contextlist->get_user()->id;
        $ids = $DB->get_fieldset_select(
            'local_chunkupload_files',
            'id',
            "userid = :userid AND contextid $insql",
            $params
        );
        self::delete_files_for_ids($ids);
    }

    /**
     * Delete data for the given users within the given context.
     *
     * @param approved_userlist $userlist The approved users and context to delete data for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }
        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $userlist->get_context()->id;
        $ids = $DB->get_fieldset_select(
            'local_chunkupload_files',
            'id',
            "contextid = :contextid AND userid $insql",
            $params
        );
        self::delete_files_for_ids($ids);
    }

    /**
     * Delete the records and on-disk files for the given chunkupload ids.
     *
     * @param int[] $ids The chunkupload ids to delete.
     */
    protected static function delete_files_for_ids(array $ids) {
        foreach ($ids as $id) {
            chunkupload_form_element::delete_file($id);
        }
    }
}
