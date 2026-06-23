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
use context_system;
use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\transform;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_chunkupload\chunkupload_form_element;
use stdClass;

/**
 * Privacy provider for local_chunkupload.
 *
 * The plugin stores a temporary record per upload in {local_chunkupload_files},
 * together with the uploaded file content on the filesystem, until the cleanup
 * task removes it. Each record is tied to the uploading user and a context.
 *
 * Because the table has no foreign key on contextid and cleanup is time-based,
 * a record can outlive the context it was created in. Such orphaned records are
 * reported under the system context so they can still be exported and deleted.
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

        // Only return contexts that still exist; a deleted course or module can
        // leave rows behind because the table has no cascade on contextid.
        $sql = "SELECT DISTINCT f.contextid
                  FROM {local_chunkupload_files} f
                  JOIN {context} ctx ON ctx.id = f.contextid
                 WHERE f.userid = :userid";
        $contextlist->add_from_sql($sql, ['userid' => $userid]);

        // Surface orphaned rows under the system context so they remain
        // exportable and deletable.
        if (self::get_orphaned_ids_for_user($userid)) {
            $contextlist->add_from_sql(
                "SELECT id FROM {context} WHERE contextlevel = :systemlevel",
                ['systemlevel' => CONTEXT_SYSTEM]
            );
        }

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

        // Orphaned rows are reported under the system context.
        if ($context->id == context_system::instance()->id) {
            $orphansql = "SELECT f.userid
                            FROM {local_chunkupload_files} f
                       LEFT JOIN {context} ctx ON ctx.id = f.contextid
                           WHERE ctx.id IS NULL AND f.userid IS NOT NULL";
            $userlist->add_from_sql('userid', $orphansql, []);
        }
    }

    /**
     * Export all user data for the given approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export data for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $systemcontextid = context_system::instance()->id;

        foreach ($contextlist->get_contexts() as $context) {
            $records = $DB->get_records(
                'local_chunkupload_files',
                ['userid' => $userid, 'contextid' => $context->id]
            );

            // Orphaned rows are exported under the system context.
            if ($context->id == $systemcontextid) {
                $records += self::get_orphaned_records_for_user($userid);
            }

            if ($records) {
                self::export_records($context, $records);
            }
        }
    }

    /**
     * Export the metadata and uploaded file content for a set of records.
     *
     * @param context $context The context to export within.
     * @param stdClass[] $records The chunkupload records to export.
     */
    protected static function export_records(context $context, array $records) {
        $writer = writer::with_context($context);
        $subcontext = [get_string('pluginname', 'local_chunkupload')];

        $metadata = [];
        foreach ($records as $record) {
            $metadata[] = (object) [
                'filename' => $record->filename,
                'state' => $record->state,
                'lastmodified' => $record->lastmodified ?
                    transform::datetime($record->lastmodified) : null,
            ];

            // Include the uploaded bytes still held in dataroot, if present.
            $path = chunkupload_form_element::get_path_for_id($record->id);
            if ($path && file_exists($path)) {
                raise_memory_limit(MEMORY_EXTRA);
                $writer->export_custom_file(
                    $subcontext,
                    self::export_filename($record),
                    file_get_contents($path)
                );
                reduce_memory_limit(MEMORY_STANDARD);
            }
        }

        $writer->export_data($subcontext, (object) ['files' => $metadata]);
    }

    /**
     * Build a unique, non-empty export filename for a record.
     *
     * @param stdClass $record The chunkupload record.
     * @return string The filename to export the content under.
     */
    protected static function export_filename(stdClass $record): string {
        $name = (string) $record->filename;
        if ($name === '') {
            $name = 'file';
        }
        // Prefix with the record id to keep filenames unique within the export.
        return $record->id . '_' . $name;
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

        // Orphaned rows are handled under the system context.
        if ($context->id == context_system::instance()->id) {
            $ids = array_merge($ids, self::get_orphaned_ids());
        }

        self::delete_files_for_ids($ids);
    }

    /**
     * Delete all data for the user in the given approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user to delete data for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;
        $userid = $contextlist->get_user()->id;
        $contextids = $contextlist->get_contextids();
        if (!$contextids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($contextids, SQL_PARAMS_NAMED);
        $params['userid'] = $userid;
        $ids = $DB->get_fieldset_select(
            'local_chunkupload_files',
            'id',
            "userid = :userid AND contextid $insql",
            $params
        );

        // Orphaned rows are handled under the system context.
        if (in_array(context_system::instance()->id, $contextids)) {
            $ids = array_merge($ids, self::get_orphaned_ids_for_user($userid));
        }

        self::delete_files_for_ids($ids);
    }

    /**
     * Delete data for the given users within the given context.
     *
     * @param approved_userlist $userlist The approved users and context to delete data for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;
        $context = $userlist->get_context();
        $userids = $userlist->get_userids();
        if (!$userids) {
            return;
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $params['contextid'] = $context->id;
        $ids = $DB->get_fieldset_select(
            'local_chunkupload_files',
            'id',
            "contextid = :contextid AND userid $insql",
            $params
        );

        // Orphaned rows are handled under the system context.
        if ($context->id == context_system::instance()->id) {
            [$uin, $uparams] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            $orphansql = "SELECT f.id
                            FROM {local_chunkupload_files} f
                       LEFT JOIN {context} ctx ON ctx.id = f.contextid
                           WHERE ctx.id IS NULL AND f.userid $uin";
            $ids = array_merge($ids, $DB->get_fieldset_sql($orphansql, $uparams));
        }

        self::delete_files_for_ids($ids);
    }

    /**
     * Get the records for a user whose context no longer exists.
     *
     * @param int $userid The user id.
     * @return stdClass[] The orphaned records, keyed by id.
     */
    protected static function get_orphaned_records_for_user(int $userid): array {
        global $DB;
        $sql = "SELECT f.*
                  FROM {local_chunkupload_files} f
             LEFT JOIN {context} ctx ON ctx.id = f.contextid
                 WHERE f.userid = :userid AND ctx.id IS NULL";
        return $DB->get_records_sql($sql, ['userid' => $userid]);
    }

    /**
     * Get the ids for a user whose context no longer exists.
     *
     * @param int $userid The user id.
     * @return int[] The orphaned record ids.
     */
    protected static function get_orphaned_ids_for_user(int $userid): array {
        global $DB;
        $sql = "SELECT f.id
                  FROM {local_chunkupload_files} f
             LEFT JOIN {context} ctx ON ctx.id = f.contextid
                 WHERE f.userid = :userid AND ctx.id IS NULL";
        return $DB->get_fieldset_sql($sql, ['userid' => $userid]);
    }

    /**
     * Get all ids whose context no longer exists.
     *
     * @return int[] The orphaned record ids.
     */
    protected static function get_orphaned_ids(): array {
        global $DB;
        $sql = "SELECT f.id
                  FROM {local_chunkupload_files} f
             LEFT JOIN {context} ctx ON ctx.id = f.contextid
                 WHERE ctx.id IS NULL";
        return $DB->get_fieldset_sql($sql);
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
