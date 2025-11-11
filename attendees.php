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
 * Copyright (C) 2007-2011 Catalyst IT (http://www.catalyst.net.nz)
 * Copyright (C) 2011-2013 Totara LMS (http://www.totaralms.com)
 * Copyright (C) 2014 onwards Catalyst IT (http://www.catalyst-eu.net)
 *
 * @package    mod
 * @subpackage facetoface
 * @copyright  2014 onwards Catalyst IT <http://www.catalyst-eu.net>
 */

require_once(dirname(dirname(dirname(__FILE__))) . '/config.php');
require_once($CFG->dirroot . '/mod/facetoface/lib.php');

// Face-to-face session ID.
$s = required_param('s', PARAM_INT);

$takeattendance = optional_param('takeattendance', false, PARAM_BOOL); // Take attendance.
$cancelform = optional_param('cancelform', false, PARAM_BOOL); // Cancel request.
$backtoallsessions = optional_param('backtoallsessions', 0, PARAM_INT); // Face-to-face activity to return to.
$download = optional_param('download', '', PARAM_ALPHA); // Download attendees.

// Sorting params (server-side sort like participants page).
$tsort = optional_param('tsort', '', PARAM_ALPHA);
$tdir = optional_param('tdir', 'ASC', PARAM_ALPHA); // 'ASC' or 'DESC'
$tdir = strtoupper($tdir);

// Load data.
if (!$session = facetoface_get_session($s)) {
    throw new moodle_exception('error:incorrectcoursemodulesession', 'facetoface');
}
if (!$facetoface = $DB->get_record('facetoface', ['id' => $session->facetoface])) {
    throw new moodle_exception('error:incorrectfacetofaceid', 'facetoface');
}
if (!$course = $DB->get_record('course', ['id' => $facetoface->course])) {
    throw new moodle_exception('error:coursemisconfigured', 'facetoface');
}
if (!$cm = get_coursemodule_from_instance('facetoface', $facetoface->id, $course->id)) {
    throw new moodle_exception('error:incorrectcoursemodule', 'facetoface');
}

// Load attendees.
$attendees = facetoface_get_attendees($session->id);

// Preload user records for attendees (single query to avoid N+1).
$users = [];
if (!empty($attendees)) {
    $userids = array_map(function($a) { return $a->id; }, $attendees);
    $userids = array_unique($userids);
    if (!empty($userids)) {
        // fetch only id and username (username is guaranteed to exist)
        $users = $DB->get_records_list('user', 'id', $userids, '', 'id, username');
    }
}

// Default to sorting by lastname (i.e. fullname order) if no tsort provided.
if (empty($tsort)) {
    $tsort = 'lastname';
}

// Server-side sorting: implement username, lastname, firstname and attendance sorts.
// lastname acts as the fullname default (lastname + firstname).
if ($tsort === 'username') {
    usort($attendees, function($a, $b) use ($users, $tdir) {
        $ua = isset($users[$a->id]) ? $users[$a->id]->username : '';
        $ub = isset($users[$b->id]) ? $users[$b->id]->username : '';
        $cmp = strcasecmp($ua, $ub);
        if ($cmp === 0) {
            $cmp = strcasecmp($a->lastname . $a->firstname, $b->lastname . $b->firstname);
        }
        return ($tdir === 'DESC') ? -$cmp : $cmp;
    });
} else if ($tsort === 'firstname') {
    usort($attendees, function($a, $b) use ($tdir) {
        $ua = $a->firstname . ' ' . $a->lastname;
        $ub = $b->firstname . ' ' . $b->lastname;
        $cmp = strcasecmp($ua, $ub);
        if ($cmp === 0) {
            return ($tdir === 'DESC') ? ($b->id - $a->id) : ($a->id - $b->id);
        }
        return ($tdir === 'DESC') ? -$cmp : $cmp;
    });
} else if ($tsort === 'attendance') {
    // Compare by status string (localized) fallback to numeric statuscode.
    usort($attendees, function($a, $b) use ($tdir) {
        $sa = get_string('status_'.facetoface_get_status($a->statuscode), 'facetoface');
        $sb = get_string('status_'.facetoface_get_status($b->statuscode), 'facetoface');
        $cmp = strcasecmp($sa, $sb);
        if ($cmp === 0) {
            return ($tdir === 'DESC') ? ($b->id - $a->id) : ($a->id - $b->id);
        }
        return ($tdir === 'DESC') ? -$cmp : $cmp;
    });
} else {
    // lastname and default.
    usort($attendees, function($a, $b) use ($tdir) {
        $ua = $a->lastname . ' ' . $a->firstname;
        $ub = $b->lastname . ' ' . $b->firstname;
        $cmp = strcasecmp($ua, $ub);
        if ($cmp === 0) {
            return ($tdir === 'DESC') ? ($b->id - $a->id) : ($a->id - $b->id);
        }
        return ($tdir === 'DESC') ? -$cmp : $cmp;
    });
}

// Load cancellations.
$cancellations = facetoface_get_cancellations($session->id);

/*
 * Capability checks to see if the current user can view this page
 *
 * This page is a bit of a special case in this respect as there are four uses for this page.
 *
 * 1) Viewing attendee list
 *   - Requires mod/facetoface:viewattendees capability in the course
 *
 * 2) Viewing cancellation list
 *   - Requires mod/facetoface:viewcancellations capability in the course
 *
 * 3) Taking attendance
 *   - Requires mod/facetoface:takeattendance capabilities in the course
 */
$context = context_course::instance($course->id);
$contextmodule = context_module::instance($cm->id);
require_course_login($course, true, $cm);

// Actions the user can perform.
$canviewattendees = has_capability('mod/facetoface:viewattendees', $context);
$cantakeattendance = has_capability('mod/facetoface:takeattendance', $context);
$canviewcancellations = has_capability('mod/facetoface:viewcancellations', $context);
$canviewsession = $canviewattendees || $cantakeattendance || $canviewcancellations;
$canapproverequests = false;

$requests = [];
$declines = [];

// If a user can take attendance, they can approve staff's booking requests.
if ($cantakeattendance) {
    $requests = facetoface_get_requests($session->id);
}

// If requests found (but not in the middle of taking attendance), show requests table.
if ($requests && !$takeattendance) {
    $canapproverequests = true;
}

// Check the user is allowed to view this page.
if (!$canviewattendees && !$cantakeattendance && !$canapproverequests && !$canviewcancellations) {
    throw new moodle_exception('nopermissions', '', "{$CFG->wwwroot}/mod/facetoface/view.php?id={$cm->id}", get_string('view'));
}

// Check user has permissions to take attendance.
if ($takeattendance && !$cantakeattendance) {
    throw new moodle_exception('nopermissions', '', '', get_capability_string('mod/facetoface:takeattendance'));
}

if (!empty($download) && $canviewattendees) {
    // Download list of attendees.
    facetoface_download_attendees(format_string($facetoface->name), $session, $attendees, $download);
    exit();
}

/*
 * Handle submitted data
 */
if ($form = data_submitted()) {
    if (!confirm_sesskey()) {
        throw new moodle_exception('confirmsesskeybad', 'error');
    }

    $return = "{$CFG->wwwroot}/mod/facetoface/attendees.php?s={$s}&backtoallsessions={$backtoallsessions}";

    if ($cancelform) {
        redirect($return);
    } else if (!empty($form->requests)) {
        // Approve requests.
        if ($canapproverequests && facetoface_approve_requests($form)) {
            // Logging and events trigger.
            $params = [
                'context'  => $contextmodule,
                'objectid' => $session->id,
            ];
            $event = \mod_facetoface\event\approve_requests::create($params);
            $event->add_record_snapshot('facetoface_sessions', $session);
            $event->add_record_snapshot('facetoface', $facetoface);
            $event->trigger();
        }

        redirect($return);
    } else if ($takeattendance) {
        if (facetoface_take_attendance($form)) {
            // Logging and events trigger.
            $params = [
                'context'  => $contextmodule,
                'objectid' => $session->id,
            ];
            $event = \mod_facetoface\event\take_attendance::create($params);
            $event->add_record_snapshot('facetoface_sessions', $session);
            $event->add_record_snapshot('facetoface', $facetoface);
            $event->trigger();
        } else {
            // Logging and events trigger.
            $params = [
                'context'  => $contextmodule,
                'objectid' => $session->id,
            ];
            $event = \mod_facetoface\event\take_attendance_failed::create($params);
            $event->add_record_snapshot('facetoface_sessions', $session);
            $event->add_record_snapshot('facetoface', $facetoface);
            $event->trigger();
        }
        redirect($return.'&takeattendance=1');
    }
}

/*
 * Print page header
 */

// Logging and events trigger.
$params = [
    'context'  => $contextmodule,
    'objectid' => $session->id,
];
$event = \mod_facetoface\event\attendees_viewed::create($params);
$event->add_record_snapshot('facetoface_sessions', $session);
$event->add_record_snapshot('facetoface', $facetoface);
$event->trigger();

$pagetitle = format_string($facetoface->name);

$PAGE->set_url('/mod/facetoface/attendees.php', ['s' => $s]);
$PAGE->set_title($pagetitle);
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();

/*
 * Print page content
 */

// If taking attendance, make sure the session has already started.
if ($takeattendance && $session->datetimeknown && !facetoface_has_session_started($session, time())) {
    $link = "{$CFG->wwwroot}/mod/facetoface/attendees.php?s={$session->id}";
    throw new moodle_exception('error:canttakeattendanceforunstartedsession', 'facetoface', $link);
}

echo $OUTPUT->box_start();
echo $OUTPUT->heading(format_string($facetoface->name));

if ($canviewsession) {
    echo facetoface_print_session($session, true);
}

/*
 * Print attendees (if user able to view)
 */
if ($canviewattendees || $cantakeattendance) {
    if ($takeattendance) {
        $heading = get_string('takeattendance', 'facetoface');
    } else {
        $heading = get_string('attendees', 'facetoface');
    }

    echo $OUTPUT->heading($heading);

    if (empty($attendees)) {
        echo $OUTPUT->notification(get_string('nosignedupusers', 'facetoface'));
    } else {
        if ($takeattendance) {
            $attendeesurl = new moodle_url('attendees.php', ['s' => $s, 'takeattendance' => '1']);
            echo html_writer::start_tag('form', ['action' => $attendeesurl, 'method' => 'post']);
            echo html_writer::tag('p', get_string('attendanceinstructions', 'facetoface'));
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $USER->sesskey]);
            echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 's', 'value' => $s]);
            echo html_writer::empty_tag('input', [
                'type' => 'hidden', ' name' => 'backtoallsessions',
                'value' => $backtoallsessions,
            ]) . '</p>';

            // Prepare status options array.
            $statuses = facetoface_statuses();
            $statusoptions = [];
            foreach ($statuses as $key => $value) {
                if ($key <= MDL_F2F_STATUS_BOOKED) {
                    continue;
                }

                $statusoptions[$key] = get_string('status_'.$value, 'facetoface');
            }
        }

        // Small inline style for the sort indicator (keeps change minimal and immediately visible).
        echo '<style>.facetoface-sort-indicator{margin-left:.25em;font-size:.9em}</style>';

        $table = new html_table();

        // Build table headers with sorting links for lastname, firstname, username (if shown), and attendance.
        // We'll split the name into Last name and First name columns for clarity.

        // Base table classes: append our class (do not replace any existing classes).
        if (empty($table->attributes)) {
            $table->attributes = [];
        }
        if (!empty($table->attributes['class'])) {
            $table->attributes['class'] .= ' facetoface_attendees_table';
        } else {
            $table->attributes['class'] = 'facetoface_attendees_table';
        }

        // Prepare base params so sort links preserve context (takeattendance/backtoallsessions).
        $baseparams = ['s' => $s, 'backtoallsessions' => $backtoallsessions];
        if ($takeattendance) {
            $baseparams['takeattendance'] = '1';
        }

        // LASTNAME header link.
        $nextdir_last = (!empty($tsort) && ($tsort === 'lastname') && $tdir === 'ASC') ? 'DESC' : 'ASC';
        $lastsortparams = $baseparams;
        $lastsortparams['tsort'] = 'lastname';
        $lastsortparams['tdir'] = $nextdir_last;
        $lastsorturl = new moodle_url('/mod/facetoface/attendees.php', $lastsortparams);

        if (!empty($tsort) && $tsort === 'lastname') {
            $last_indicator = ($tdir === 'ASC') ? '▲' : '▼';
            $last_aria = ($tdir === 'ASC') ? 'ascending' : 'descending';
        } else {
            $last_indicator = '';
            $last_aria = 'none';
        }

        // FIRSTNAME header link.
        $nextdir_first = (!empty($tsort) && ($tsort === 'firstname') && $tdir === 'ASC') ? 'DESC' : 'ASC';
        $firstsortparams = $baseparams;
        $firstsortparams['tsort'] = 'firstname';
        $firstsortparams['tdir'] = $nextdir_first;
        $firstsorturl = new moodle_url('/mod/facetoface/attendees.php', $firstsortparams);

        if (!empty($tsort) && $tsort === 'firstname') {
            $first_indicator = ($tdir === 'ASC') ? '▲' : '▼';
            $first_aria = ($tdir === 'ASC') ? 'ascending' : 'descending';
        } else {
            $first_indicator = '';
            $first_aria = 'none';
        }

        // USERNAME header link.
        $nextdir_user = (!empty($tsort) && ($tsort === 'username') && $tdir === 'ASC') ? 'DESC' : 'ASC';
        $usersortparams = $baseparams;
        $usersortparams['tsort'] = 'username';
        $usersortparams['tdir'] = $nextdir_user;
        $usersorturl = new moodle_url('/mod/facetoface/attendees.php', $usersortparams);

        if (!empty($tsort) && $tsort === 'username') {
            $user_indicator = ($tdir === 'ASC') ? '▲' : '▼';
            $user_aria = ($tdir === 'ASC') ? 'ascending' : 'descending';
        } else {
            $user_indicator = '';
            $user_aria = 'none';
        }

        // ATTENDANCE header link.
        $nextdir_att = (!empty($tsort) && ($tsort === 'attendance') && $tdir === 'ASC') ? 'DESC' : 'ASC';
        $attsortparams = $baseparams;
        $attsortparams['tsort'] = 'attendance';
        $attsortparams['tdir'] = $nextdir_att;
        $attsorturl = new moodle_url('/mod/facetoface/attendees.php', $attsortparams);

        if (!empty($tsort) && $tsort === 'attendance') {
            $att_indicator = ($tdir === 'ASC') ? '▲' : '▼';
            $att_aria = ($tdir === 'ASC') ? 'ascending' : 'descending';
        } else {
            $att_indicator = '';
            $att_aria = 'none';
        }

        // Now build header depending on whether we're taking attendance.
        if ($takeattendance) {
            // Take attendance view: Last, First, Current status (sortable), Attended (select)
            $table->head = [
                html_writer::link(
                    $lastsorturl,
                    get_string('lastname', 'facetoface') .
                    html_writer::tag('span', $last_indicator, ['class' => 'facetoface-sort-indicator', 'aria-hidden' => 'true']) .
                    html_writer::tag('span', ' ' . ($nextdir_last === 'ASC' ? get_string('sortbyascending', 'facetoface', get_string('lastname', 'facetoface')) : get_string('sortbydescending', 'facetoface', get_string('lastname', 'facetoface'))),
                        ['class' => 'accesshide']),
                    ['data-sortable' => '1', 'data-sortby' => 'lastname', 'data-sortorder' => ($nextdir_last === 'ASC' ? '1' : '0'), 'role' => 'button', 'aria-sort' => $last_aria]
                ),
                html_writer::link(
                    $firstsorturl,
                    get_string('firstname', 'facetoface') .
                    html_writer::tag('span', $first_indicator, ['class' => 'facetoface-sort-indicator', 'aria-hidden' => 'true']) .
                    html_writer::tag('span', ' ' . ($nextdir_first === 'ASC' ? get_string('sortbyascending', 'facetoface', get_string('firstname', 'facetoface')) : get_string('sortbydescending', 'facetoface', get_string('firstname', 'facetoface'))),
                        ['class' => 'accesshide']),
                    ['data-sortable' => '1', 'data-sortby' => 'firstname', 'data-sortorder' => ($nextdir_first === 'ASC' ? '1' : '0'), 'role' => 'button', 'aria-sort' => $first_aria]
                ),
                html_writer::link(
                    $attsorturl,
                    get_string('currentstatus', 'facetoface') .
                    html_writer::tag('span', $att_indicator, ['class' => 'facetoface-sort-indicator', 'aria-hidden' => 'true']) .
                    html_writer::tag('span', ' ' . ($nextdir_att === 'ASC' ? get_string('sortbyascending', 'facetoface', get_string('currentstatus', 'facetoface')) : get_string('sortbydescending', 'facetoface', get_string('currentstatus', 'facetoface'))),
                        ['class' => 'accesshide']),
                    ['data-sortable' => '1', 'data-sortby' => 'attendance', 'data-sortorder' => ($nextdir_att === 'ASC' ? '1' : '0'), 'role' => 'button', 'aria-sort' => $att_aria]
                ),
                get_string('attendedsession', 'facetoface')
            ];
            $table->align = ['left', 'left', 'center', 'center'];
            $table->size = ['30%', '30%', '20%', '20%'];
        } else {
            // Normal view: Last, First, Username (sortable), maybe cost/discount, Attendance (sortable)
            $head = [];
            $head[] = html_writer::link(
                $lastsorturl,
                get_string('lastname', 'facetoface') .
                html_writer::tag('span', $last_indicator, ['class' => 'facetoface-sort-indicator', 'aria-hidden' => 'true']) .
                html_writer::tag('span', ' ' . ($nextdir_last === 'ASC' ? get_string('sortbyascending', 'facetoface', get_string('lastname', 'facetoface')) : get_string('sortbydescending', 'facetoface', get_string('lastname', 'facetoface'))),
                    ['class' => 'accesshide']),
                ['data-sortable' => '1', 'data-sortby' => 'lastname', 'data-sortorder' => ($nextdir_last === 'ASC' ? '1' : '0'), 'role' => 'button', 'aria-sort' => $last_aria]
            );
            $head[] = html_writer::link(
                $firstsorturl,
                get_string('firstname', 'facetoface') .
                html_writer::tag('span', $first_indicator, ['class' => 'facetoface-sort-indicator', 'aria-hidden' => 'true']) .
                html_writer::tag('span', ' ' . ($nextdir_first === 'ASC' ? get_string('sortbyascending', 'facetoface', get_string('firstname', 'facetoface')) : get_string('sortbydescending', 'facetoface', get_string('firstname', 'facetoface'))),
                    ['class' => 'accesshide']),
                ['data-sortable' => '1', 'data-sortby' => 'firstname', 'data-sortorder' => ($nextdir_first === 'ASC' ? '1' : '0'), 'role' => 'button', 'aria-sort' => $first_aria]
            );

            // Username header (sortable)
            $head[] = html_writer::link(
                $usersorturl,
                get_string('username', 'facetoface') .
                html_writer::tag('span', $user_indicator, ['class' => 'facetoface-sort-indicator', 'aria-hidden' => 'true']) .
                html_writer::tag('span', ' ' . ($nextdir_user === 'ASC' ? get_string('sortbyascending', 'facetoface', get_string('username', 'facetoface')) : get_string('sortbydescending', 'facetoface', get_string('username', 'facetoface'))),
                    ['class' => 'accesshide']),
                ['data-sortable' => '1', 'data-sortby' => 'username', 'data-sortorder' => ($nextdir_user === 'ASC' ? '1' : '0'), 'role' => 'button', 'aria-sort' => $user_aria]
            );

            // Optional cost/discount columns (preserve previous layout).
            if (!get_config('facetoface', 'hidecost')) {
                $head[] = get_string('cost', 'facetoface');
                if (!get_config('facetoface', 'hidediscount')) {
                    $head[] = get_string('discountcode', 'facetoface');
                }
            }

            // Attendance (sortable)
            $head[] = html_writer::link(
                $attsorturl,
                get_string('attendance', 'facetoface') .
                html_writer::tag('span', $att_indicator, ['class' => 'facetoface-sort-indicator', 'aria-hidden' => 'true']) .
                html_writer::tag('span', ' ' . ($nextdir_att === 'ASC' ? get_string('sortbyascending', 'facetoface', get_string('attendance', 'facetoface')) : get_string('sortbydescending', 'facetoface', get_string('attendance', 'facetoface'))),
                    ['class' => 'accesshide']),
                ['data-sortable' => '1', 'data-sortby' => 'attendance', 'data-sortorder' => ($nextdir_att === 'ASC' ? '1' : '0'), 'role' => 'button', 'aria-sort' => $att_aria]
            );

            $table->head = $head;
            // Build align array consistent with head columns:
            $table->align = ['left', 'left', 'left'];
            if (!get_config('facetoface', 'hidecost')) {
                $table->align[] = 'center';
                if (!get_config('facetoface', 'hidediscount')) {
                    $table->align[] = 'center';
                }
            }
            $table->align[] = 'center';
            $table->size = ['20%', '20%', '20%', '15%', '15%'];
        }

        // Populate rows.
        foreach ($attendees as $attendee) {
            $data = [];

            // Last name cell (link to profile using lastname text).
            $attendeeurl = new moodle_url('/user/view.php', ['id' => $attendee->id, 'course' => $course->id]);
            $data[] = html_writer::link($attendeeurl, format_string($attendee->lastname));

            // First name cell.
            $data[] = format_string($attendee->firstname);

            if ($takeattendance) {
                // Show current status (text)
                $data[] = get_string('status_'.facetoface_get_status($attendee->statuscode), 'facetoface');

                // Select to update attended status (as previous behaviour)
                $optionid = 'submissionid_'.$attendee->submissionid;
                $status = $attendee->statuscode;
                $select = html_writer::select($statusoptions, $optionid, $status);
                $data[] = $select;
            } else {
                // Username column (preloaded)
                $user = isset($users[$attendee->id]) ? $users[$attendee->id] : null;
                $username = $user ? $user->username : '';
                $data[] = format_string($username);

                // cost/discount if enabled
                if (!get_config('facetoface', 'hidecost')) {
                    $data[] = facetoface_cost($attendee->id, $session->id, $session);
                    if (!get_config('facetoface', 'hidediscount')) {
                        $data[] = $attendee->discountcode;
                    }
                }

                // Attendance text
                $data[] = str_replace(' ', '&nbsp;',
                    get_string('status_'.facetoface_get_status($attendee->statuscode), 'facetoface'));
            }

            $table->data[] = $data;
        }

        echo html_writer::table($table);

        if ($takeattendance) {
            echo html_writer::start_tag('p');
            echo html_writer::empty_tag('input', ['type' => 'submit', 'value' => get_string('saveattendance', 'facetoface')]);
            echo '&nbsp;' . html_writer::empty_tag('input', [
                'type' => 'submit', 'name' => 'cancelform',
                'value' => get_string('cancel'),
            ]);
            echo html_writer::end_tag('p') . html_writer::end_tag('form');
        } else {
            // Actions.
            print html_writer::start_tag('p');
            if ($cantakeattendance && $session->datetimeknown && facetoface_has_session_started($session, time())) {
                // Take attendance.
                $attendanceurl = new moodle_url('attendees.php', [
                    's' => $session->id, 'takeattendance' => '1',
                    'backtoallsessions' => $backtoallsessions,
                ]);
                echo html_writer::link($attendanceurl, get_string('takeattendance', 'facetoface')) . ' - ';
            }
        }
    }

    if (!$takeattendance
        && (has_capability('mod/facetoface:addattendees', $context)
        || has_capability('mod/facetoface:removeattendees', $context))) {
        // Add/remove attendees.
        $editattendeeslink = new moodle_url('editattendees.php', ['s' => $session->id, 'backtoallsessions' => $backtoallsessions]);
        echo html_writer::link($editattendeeslink, get_string('addremoveattendees', 'facetoface')) . ' - ';
    }
    echo html_writer::link("attendees.php?s=$session->id&backtoallsessions=$session->facetoface&download=ods",
            get_string('downloadods', 'facetoface')) . ' - ';
    echo html_writer::link("attendees.php?s=$session->id&backtoallsessions=$session->facetoface&download=xls",
            get_string('downloadexcel', 'facetoface')) . ' - ';
}

// Go back.
$url = new moodle_url('/course/view.php', ['id' => $course->id]);
if ($backtoallsessions) {
    $url = new moodle_url('/mod/facetoface/view.php', ['f' => $facetoface->id, 'backtoallsessions' => $backtoallsessions]);
}
echo html_writer::link($url, get_string('goback', 'facetoface')) . html_writer::end_tag('p');

/*
 * Print unapproved requests (if user able to view)
 */
if ($canapproverequests) {
    echo html_writer::empty_tag('br', ['id' => 'unapproved']);
    if (!$requests) {
        echo $OUTPUT->notification(get_string('noactionableunapprovedrequests', 'facetoface'));
    } else {
        $canbookuser = (facetoface_session_has_capacity($session, $contextmodule) || $session->allowoverbook);

        $OUTPUT->heading(get_string('unapprovedrequests', 'facetoface'));

        if (!$canbookuser) {
            echo html_writer::tag('p', get_string('cannotapproveatcapacity', 'facetoface'));
        }

        $action = new moodle_url('attendees.php', ['s' => $s]);
        echo html_writer::start_tag('form', ['action' => $action->out(), 'method' => 'post']);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey', 'value' => $USER->sesskey]);
        echo html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 's', 'value' => $s]);
        echo html_writer::empty_tag('input', [
            'type' => 'hidden', 'name' => 'backtoallsessions',
            'value' => $backtoallsessions,
        ]) . html_writer::end_tag('p');

        $table = new html_table();
        $table->head = [
            get_string('name', 'facetoface'), get_string('timerequested', 'facetoface'),
            get_string('decidelater', 'facetoface'), get_string('decline', 'facetoface'),
            get_string('approve', 'facetoface'),
        ];
        $table->align = ['left', 'center', 'center', 'center', 'center'];

        foreach ($requests as $attendee) {
            $data = [];
            $attendeelink = new moodle_url('/user/view.php', ['id' => $attendee->id, 'course' => $course->id]);
            $data[] = html_writer::link($attendeelink, format_string(fullname($attendee)));
            $data[] = userdate($attendee->timerequested, get_string('strftimedatetime'));
            $data[] = html_writer::empty_tag('input', [
                'type' => 'radio', 'name' => 'requests['.$attendee->id.']',
                'value' => '0', 'checked' => 'checked',
            ]);
            $data[] = html_writer::empty_tag('input', [
                'type' => 'radio', 'name' => 'requests['.$attendee->id.']',
                'value' => '1',
            ]);
            $disabled = ($canbookuser) ? [] : ['disabled' => 'disabled'];
            $data[] = html_writer::empty_tag('input', array_merge([
                'type' => 'radio', 'name' => 'requests['.$attendee->id.']',
                'value' => '2',
            ], $disabled));
            $table->data[] = $data;
        }

        echo html_writer::table($table);

        echo html_writer::tag('p', html_writer::empty_tag('input', [
            'type' => 'submit',
            'value' => get_string('updaterequests', 'facetoface'),
        ]));
        echo html_writer::end_tag('form');
    }
}

/*
 * Print cancellations (if user able to view)
 */
if (!$takeattendance && $canviewcancellations && $cancellations) {
    echo html_writer::empty_tag('br');
    echo $OUTPUT->heading(get_string('cancellations', 'facetoface'));

    $table = new html_table();
    $table->summary = get_string('cancellationstablesummary', 'facetoface');
    $table->head = [
        get_string('name', 'facetoface'), get_string('timesignedup', 'facetoface'),
        get_string('timecancelled', 'facetoface'), get_string('cancelreason', 'facetoface'),
    ];
    $table->align = ['left', 'center', 'center'];

    foreach ($cancellations as $attendee) {
        $data = [];
        $attendeelink = new moodle_url('/user/view.php', ['id' => $attendee->id, 'course' => $course->id]);
        $data[] = html_writer::link($attendeelink, format_string(fullname($attendee)));
        $data[] = userdate($attendee->timesignedup, get_string('strftimedatetime'));
        $data[] = userdate($attendee->timecancelled, get_string('strftimedatetime'));
        $data[] = format_string($attendee->cancelreason);
        $table->data[] = $data;
    }
    echo html_writer::table($table);
}

/*
 * Print page footer
 */
echo $OUTPUT->box_end();
echo $OUTPUT->footer($course);
