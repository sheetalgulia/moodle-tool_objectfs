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
 * Pushes files to remote storage if they meet the configured criterea.
 *
 * @package   tool_objectfs
 * @author    Kenneth Hendricks <kennethhendricks@catalyst-au.net>
 * @copyright Catalyst IT
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace tool_objectfs\object_manipulator;

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/admin/tool/objectfs/lib.php');

use Aws\S3\Exception\S3Exception;

class pusher extends manipulator {

    /**
     * Size threshold for pushing files to remote in bytes.
     *
     * @var int
     */
    private $sizethreshold;

    /**
     * Minimum age of a file to be pushed to remote in seconds.
     *
     * @var int
     */
    private $minimumage;

    /**
     * Pusher constructor.
     *
     * @param object_client $client remote object client
     * @param object_file_system $filesystem objectfs file system
     * @param object $config objectfs config.
     */
    public function __construct($filesystem, $config, $logger) {
        parent::__construct($filesystem, $config);
        $this->sizethreshold = $config->sizethreshold;
        $this->minimumage = $config->minimumage;

        $this->logger = $logger;
        $this->logger->set_action('push');
    }

    /**
     * Get candidate content hashes for pushing.
     * Files that are bigger than the sizethreshold,
     * less than 5GB (S3 upload max),
     * older than the minimum age
     * and have no location / are in local.
     *
     * @return array candidate contenthashes
     */
    public function get_candidate_objects() {
        global $DB;
        $sql = 'SELECT f.contenthash,
                       MAX(f.filesize) AS filesize
                  FROM {files} f
             LEFT JOIN {tool_objectfs_objects} o ON f.contenthash = o.contenthash
              GROUP BY f.contenthash,
                       f.filesize,
                       o.location
                HAVING MIN(f.timecreated) <= ?
                       AND MAX(f.filesize) > ?
                       AND MAX(f.filesize) < 5000000000
                       AND (o.location IS NULL OR o.location = ?)';

        $maxcreatedtimestamp = time() - $this->minimumage;

        $params = array($maxcreatedtimestamp, $this->sizethreshold, OBJECT_LOCATION_LOCAL);

        $this->logger->start_timing();
        $objects = $DB->get_records_sql($sql, $params);
        $this->logger->end_timing();

        $totalobjectsfound = count($objects);

        $this->logger->log_object_manipulation_query($totalobjectsfound);

        return $objects;
    }

    protected function manipulate_object($objectrecord) {
        $success = $this->filesystem->copy_object_from_local_to_remote_by_hash($objectrecord->contenthash);

        if ($success) {
            $location = OBJECT_LOCATION_DUPLICATED;
        } else {
            $location = $this->filesystem->get_actual_object_location_by_hash($objectrecord->contenthash);
        }

        return $location;
    }

}


