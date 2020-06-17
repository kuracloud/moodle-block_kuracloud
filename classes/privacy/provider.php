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

namespace block_kuracloud\privacy;

defined('MOODLE_INTERNAL') || die();

use \core_privacy\local\metadata\collection;
use \core_privacy\local\request\contextlist;
use \core_privacy\local\request\writer;
use \core_privacy\local\request\userlist;
use \core_privacy\local\request\approved_contextlist;
use \core_privacy\local\request\approved_userlist;


class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    public static function get_metadata(collection $collection) : collection {
        // Describe the data we are storing locally
        $collection->add_database_table(
            'block_kuracloud_users',
            [
                'userid' => 'privacy:metadata:block_kuracloud_users:userid',
                'remote_studentid' => 'privacy:metadata:block_kuracloud_users:remote_studentid',

            ],
            'privacy:metadata:block_kuracloud_users'
        );

        // Describe the data exported to kuraCloud
        $collection->add_external_location_link('kuracloud_sync', [
            'firstname' => 'privacy:metadata:kuracloud_sync:firstname',
            'lastname' => 'privacy:metadata:kuracloud_sync:lastname',
            'idnumber' => 'privacy:metadata:kuracloud_sync:idnumber',
            'email' => 'privacy:metadata:kuracloud_sync:email',
        ], 'privacy:metadata:kuracloud_sync');

        return $collection;
    }


    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param   int           $userid       The user to search.
     * @return  contextlist   $contextlist  The list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid) : contextlist {
        global $DB;
        $contextlist = new contextlist();

        // Add the contexts for this user still associated with courses
        $sql = "SELECT context.id
                  FROM {context} context
            INNER JOIN {course} course on course.id = context.instanceid AND (context.contextlevel = :contextlevel)
            INNER JOIN {block_kuracloud_courses} kc_courses on kc_courses.courseid = course.id
            INNER JOIN {block_kuracloud_users} kc_users on kc_users.remote_instanceid = kc_courses.remote_instanceid AND kc_users.remote_courseid = kc_courses.remote_courseid
                 WHERE kc_users.userid = :userid
        ";
        $params = [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ];
        $contextlist->add_from_sql($sql, $params);

        // We could add the block_kuracloud_users that are no longer associated with a course to the user context????

        return $contextlist;
    }


    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users who have data in this context/plugin combination.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT kc_users.userid
                  FROM {block_kuracloud_courses} kc_courses
            INNER JOIN {block_kuracloud_users} kc_users on kc_users.remote_instanceid = kc_courses.remote_instanceid AND kc_users.remote_courseid = kc_courses.remote_courseid
                 WHERE kc_courses.courseid = :courseid
        ";
        $params = [
            'courseid' => $context->instanceid,
        ];

        $userlist->add_from_sql('userid', $sql, $params);
    }


    /**
     * Export all user data for the specified user, in the specified contexts, using the supplied exporter instance.
     *
     * @param   approved_contextlist    $contextlist    The approved contexts to export information for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }

        $userid = $contextlist->get_user()->id;
        global $DB;

        // Get a map from courseid to the user PII
        $sql = "SELECT kc_courses.courseid, kc_users.userid, kc_users.remote_studentid
                  FROM {block_kuracloud_courses} kc_courses
            INNER JOIN {block_kuracloud_users} kc_users on kc_users.remote_instanceid = kc_courses.remote_instanceid AND kc_users.remote_courseid = kc_courses.remote_courseid
                 WHERE kc_users.userid = :userid
        ";
        $params = [
            'userid' => $userid,
        ];
        $details = $DB->get_records_sql($sql, $params);

        // Export the course related user information
        foreach ($contextlist->get_contexts() as $context) {
            $courseid = $context->instanceid; // Tentative, also need to check contextlevel.
            if ($context->contextlevel === CONTEXT_COURSE && array_key_exists($courseid, $details)) {
                $data = new \stdClass();
                $data->moodle_userid = $details[$courseid]->userid;
                $data->kuracloud_studentid = $details[$courseid]->remote_studentid;
                // studentid is within instance and course, but not much value in adding them to exported data.
                writer::with_context($context)->export_data(['kuraCloud'], $data);
            }
        }
    }


    /**
     * Delete all personal data for all users in the specified context.
     *
     * @param context $context Context to delete data from.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        global $DB;
        $mapping = $DB->get_record('block_kuracloud_courses', array('courseid' => $context->instanceid));
        if ($mapping) {
            $DB->delete_records('block_kuracloud_users', array('remote_instanceid' => $mapping->remote_instanceid, 'remote_courseid' => $mapping->remote_courseid));
        }
    }


    public static function delete_data_for_user(approved_contextlist $contextlist) {
        if (empty($contextlist->count())) {
            return;
        }

        global $DB;
        $userid = $contextlist->get_user()->id;

        // Get a map from courseid to the related user record
        $sql = "SELECT kc_courses.courseid, kc_users.id
                  FROM {block_kuracloud_courses} kc_courses
            INNER JOIN {block_kuracloud_users} kc_users on kc_users.remote_instanceid = kc_courses.remote_instanceid AND kc_users.remote_courseid = kc_courses.remote_courseid
                 WHERE kc_users.userid = :userid
        ";
        $params = [
            'userid' => $userid,
        ];
        $details = $DB->get_records_sql($sql, $params);

        foreach ($contextlist->get_contexts() as $context) {
            $courseid = $context->instanceid; // Tentative, also need to check contextlevel.
            if ($context->contextlevel === CONTEXT_COURSE && array_key_exists($courseid, $details)) {
                $DB->delete_records('block_kuracloud_users', ['id' => $details[$courseid]->id, 'userid' => $userid]);
            }
        }
    }


    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete information for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        if (empty($userlist->count())) {
            return;
        }
        $context = $userlist->get_context();
        if ($context->contextlevel !== CONTEXT_COURSE) {
            return;
        }

        global $DB;
        $mapping = $DB->get_record('block_kuracloud_courses', array('courseid' => $context->instanceid));
        if ($mapping) {
            list($userinsql, $userinparams) = $DB->get_in_or_equal($userlist->get_userids(), SQL_PARAMS_NAMED);
            $params = array_merge(['remote_instanceid' => $mapping->remote_instanceid, 'remote_courseid' => $mapping->remote_courseid], $userinparams);
            $sql = "remote_instanceid = :remote_instanceid AND remote_courseid = :remote_courseid AND userid {$userinsql}";
            $DB->delete_records_select('block_kuracloud_users', $sql, $params);
        }
    }
}
