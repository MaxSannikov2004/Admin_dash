<?php
namespace local_admindash;
defined('MOODLE_INTERNAL') || die();

class api {

    public function init(): array {
        global $DB;
        $catsList = [];
        $cats = $DB->get_records_sql("SELECT id, name FROM {course_categories} ORDER BY sortorder");
        foreach ($cats as $c) { $catsList[] = ['id'=>(int)$c->id, 'name'=>(string)$c->name]; }
        return ['catsList'=>$catsList, 'coursesList'=>[]];
    }

    public function get_courses(int $categoryid, string $q=''): array {
        global $DB;
        $params = []; $where = [];
        if ($categoryid) { $where[] = 'c.category = :categoryid'; $params['categoryid'] = $categoryid; }
        if ($q !== '') { $where[] = '(c.fullname LIKE :q1 OR c.shortname LIKE :q2)'; $params['q1'] = '%'.$q.'%'; $params['q2'] = '%'.$q.'%'; }
        if (!$where) { return []; }
        $sql = 'SELECT c.id, c.fullname, c.shortname, c.category FROM {course} c WHERE '.implode(' AND ', $where).' ORDER BY c.sortorder, c.fullname';
        $recs = $DB->get_records_sql($sql, $params, 0, 300);
        $out = [];
        foreach ($recs as $r) { $out[] = ['id'=>(int)$r->id, 'fullname'=>(string)$r->fullname, 'shortname'=>(string)$r->shortname, 'category'=>(int)$r->category]; }
        return $out;
    }

    public function get_groups_for_course(int $courseid): array {
        if (!$courseid) { return []; }
        \require_capability('local/admindash:view', \context_course::instance($courseid));
        global $DB;
        $recs = $DB->get_records('groups', ['courseid'=>$courseid], 'name ASC', 'id,name');
        $out = [];
        foreach ($recs as $g) { $out[] = ['id'=>(int)$g->id, 'name'=>(string)$g->name]; }
        return $out;
    }

    public function get_grades_by_group(int $courseid, int $groupid=0, int $from=0, int $to=2147483647): array {
        global $DB, $CFG;
        \require_capability('local/admindash:view', \context_course::instance($courseid));
        require_once($CFG->libdir . '/gradelib.php');

        if ($groupid) {
            $students = $DB->get_records_sql("SELECT DISTINCT u.id, u.firstname, u.lastname FROM {groups_members} gm JOIN {user} u ON u.id=gm.userid WHERE gm.groupid=:gid ORDER BY u.lastname,u.firstname", ['gid'=>$groupid]);
        } else {
            $students = $DB->get_records_sql("SELECT DISTINCT u.id, u.firstname, u.lastname FROM {user_enrolments} ue JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid JOIN {user} u ON u.id=ue.userid ORDER BY u.lastname,u.firstname", ['cid'=>$courseid]);
        }
        $outstudents = []; $userids = [];
        foreach ($students as $u) { $outstudents[] = ['id'=>(int)$u->id, 'name'=>fullname($u)]; $userids[] = (int)$u->id; }

        $items = $DB->get_records_sql("
            SELECT gi.id, COALESCE(NULLIF(gi.itemname,''), CONCAT(gi.itemmodule, ' #', gi.iteminstance)) AS name,
                   gi.grademax AS max, gi.itemtype, gi.itemmodule, gi.iteminstance, gi.gradetype, gi.scaleid
              FROM {grade_items} gi
             WHERE gi.courseid=:cid AND gi.itemtype IN ('mod','manual')
          ORDER BY gi.sortorder
        ", ['cid'=>$courseid]);
        $outitems = [];
        foreach ($items as $it) {
            $outitems[] = [
                'id'=>(int)$it->id, 'name'=>(string)$it->name, 'max'=>(float)$it->max,
                'type'=>$it->itemtype, 'module'=>$it->itemmodule, 'instance'=>(int)$it->iteminstance,
                'gradetype'=>(int)$it->gradetype, 'scaleid'=>(int)$it->scaleid
            ];
        }

        $cells = [];
        if ($outstudents && $outitems) {
            list($stuins, $pstu) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
            list($itins,  $pitm) = $DB->get_in_or_equal(array_column($outitems,'id'),   SQL_PARAMS_NAMED);
            $grades = $DB->get_records_sql("
                SELECT gg.userid, gg.itemid, COALESCE(gg.finalgrade, gg.rawgrade) AS val
                  FROM {grade_grades} gg
                 WHERE gg.userid $stuins AND gg.itemid $itins
            ", $pstu + $pitm);
            foreach ($grades as $g) {
                $uid=(int)$g->userid; $iid=(int)$g->itemid;
                if (!isset($cells[$uid])) { $cells[$uid]=[]; }
                if ($g->val !== null) { $cells[$uid][$iid] = $g->val; }
            }
        }

        foreach ($items as $it) {
            if ($it->itemtype !== 'mod') { continue; }
            $mod = $it->itemmodule; $inst = (int)$it->iteminstance; $iid = (int)$it->id;
            if (!$mod || !$inst) { continue; }
            $gg = grade_get_grades($courseid, 'mod', $mod, $inst, $userids);
            if (empty($gg->items) || empty($gg->items[0]->grades)) { continue; }
            $grades = $gg->items[0]->grades;
            foreach ($userids as $uid) {
                $g = $grades[$uid] ?? null;
                if (!$g) { continue; }
                $val = null;
                if (isset($g->finalgrade)) { $val = $g->finalgrade; }
                elseif (isset($g->grade)) { $val = $g->grade; }
                elseif (isset($g->rawgrade)) { $val = $g->rawgrade; }
                if ($val !== null) {
                    if (!isset($cells[$uid])) { $cells[$uid]=[]; }
                    if (!array_key_exists($iid, $cells[$uid])) { $cells[$uid][$iid] = $val; }
                }
            }
        }

        // Attach curator notes only if the current user may comment (curator role).
        $cancomment = \has_capability('local/admindash:comment', \context_course::instance($courseid));
        $this->attach_notes($cells, $courseid, $groupid, $userids, 'grade', array_column($outitems,'id'));
        

        return ['students'=>$outstudents, 'items'=>$outitems, 'cells'=>$cells, 'cancomment'=> (bool)$cancomment];
    }

    public function get_attendance_by_group(int $courseid, int $groupid=0, int $from=0, int $to=2147483647): array {
        global $DB;
        \require_capability('local/admindash:view', \context_course::instance($courseid));

        if ($groupid) {
            $students = $DB->get_records_sql("SELECT DISTINCT u.id, u.firstname, u.lastname FROM {groups_members} gm JOIN {user} u ON u.id=gm.userid WHERE gm.groupid=:gid ORDER BY u.lastname,u.firstname", ['gid'=>$groupid]);
        } else {
            $students = $DB->get_records_sql("SELECT DISTINCT u.id, u.firstname, u.lastname FROM {user_enrolments} ue JOIN {enrol} e ON e.id=ue.enrolid AND e.courseid=:cid JOIN {user} u ON u.id=ue.userid ORDER BY u.lastname,u.firstname", ['cid'=>$courseid]);
        }
        $outstudents = []; $userids=[]; foreach ($students as $u) { $outstudents[] = ['id'=>(int)$u->id, 'name'=>fullname($u)]; $userids[]=(int)$u->id; }

        $outsessions = []; $cells = []; $kpi = 0.0; $attendance_available = false;
        if ($DB->get_manager()->table_exists('attendance_sessions')) {
            $attendance_available = true;
            $sessions = $DB->get_records_sql("SELECT s.id, s.sessdate FROM {attendance_sessions} s JOIN {attendance} a ON a.id=s.attendanceid WHERE a.course=:cid AND s.sessdate BETWEEN :from AND :to ORDER BY s.sessdate ASC", ['cid'=>$courseid,'from'=>$from,'to'=>$to]);
            foreach ($sessions as $s) { $outsessions[] = ['id'=>(int)$s->id, 'date'=>(int)$s->sessdate]; }
            if ($outsessions) {
                list($sins, $ps) = $DB->get_in_or_equal(array_column($outsessions, 'id'), SQL_PARAMS_NAMED);
                $hasremarks = $DB->get_manager()->field_exists('attendance_log', 'remarks');
                $remarkscol = $hasremarks ? ", lm.remarks AS comment" : ", '' AS comment";
                $marks = $DB->get_records_sql("
                    SELECT lm.studentid AS userid, lm.sessionid, lm.statusid, st.acronym AS status {$remarkscol}
                      FROM {attendance_log} lm
                      JOIN {attendance_statuses} st ON st.id=lm.statusid
                     WHERE lm.sessionid $sins
                ", $ps);
                foreach ($marks as $m) {
                    $uid=(int)$m->userid; $sid=(int)$m->sessionid;
                    if (!isset($cells[$uid])) { $cells[$uid]=[]; }
                    $cells[$uid][$sid] = ['statusid'=>(int)$m->statusid, 'status'=>(string)$m->status, 'comment'=>(string)($m->comment ?? '')];
                }
                $sum=0; $n=0;
                foreach ($cells as $per){ $tot=count($per); if ($tot){ $pr=0; foreach($per as $mk){ $ac=strtolower((string)($mk['status']??'')); if (in_array($ac,['p','пр','present'])) $pr++; } $sum += $pr/$tot; $n++; } }
                $kpi = $n ? round(100.0*$sum/$n, 2) : 0.0;
            }
        }

        // Attach curator notes only if the current user may comment (curator role).
        $cancomment = \has_capability('local/admindash:comment', \context_course::instance($courseid));
        $this->attach_notes($cells, $courseid, $groupid, $userids, 'att', array_column($outsessions,'id'));
        

        return ['students'=>$outstudents, 'sessions'=>$outsessions, 'cells'=>$cells, 'kpi'=>$kpi, 'cancomment'=> (bool)$cancomment, 'attendance_available' => (bool)$attendance_available];
    }

    private function attach_notes(array &$cells, int $courseid, int $groupid, array $userids, string $itemtype, array $itemids): void {
        global $DB;
        if (empty($userids) || empty($itemids)) { return; }
        list($uins, $up) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        list($iins,  $ip) = $DB->get_in_or_equal($itemids, SQL_PARAMS_NAMED);
        $params = ['cid'=>$courseid, 'gid'=>$groupid, 't'=>$itemtype] + $up + $ip;
        $recs = $DB->get_records_sql("SELECT userid, itemid, note, priority FROM {local_admindash_notes} WHERE courseid=:cid AND groupid=:gid AND itemtype=:t AND userid $uins AND itemid $iins", $params);
        foreach ($recs as $r) {
            $uid = (int)$r->userid; $iid = (int)$r->itemid;
            if (!isset($cells[$uid])) { $cells[$uid] = []; }
            if (!isset($cells[$uid][$iid])) { $cells[$uid][$iid] = []; }
            $p = isset($r->priority) ? (int)$r->priority : 0;
            if (is_array($cells[$uid][$iid])) {
                $cells[$uid][$iid]['cdnote'] = (string)$r->note;
                $cells[$uid][$iid]['cdpriority'] = $p;
            } else {
                $cells[$uid][$iid] = ['val'=>$cells[$uid][$iid], 'cdnote'=>(string)$r->note, 'cdpriority'=>$p];
            }
        }
    }

    public function save_note(int $courseid, int $groupid, int $userid, string $itemtype, int $itemid, string $note, int $priority = 0): array {
        global $DB, $USER;
        \require_capability('local/admindash:comment', \context_course::instance($courseid));
        $note = trim(clean_text($note, FORMAT_PLAIN));
        $now = time();

        $where = ['courseid'=>$courseid,'groupid'=>$groupid,'userid'=>$userid,'itemtype'=>$itemtype,'itemid'=>$itemid];
        $existing = $DB->get_record('local_admindash_notes', $where);

        if ($note === '') {
            if ($existing) { $DB->delete_records('local_admindash_notes', ['id'=>$existing->id]); }
            return ['ok'=>true, 'deleted'=>true];
        }

        if ($existing) {
            $existing->note = $note;
            $existing->priority = $priority;
            $existing->authorid = $USER->id;
            $existing->timemodified = $now;
            $DB->update_record('local_admindash_notes', $existing);
            $id = $existing->id;
        } else {
            $rec = (object)$where + (object)[
                'note'=>$note,'priority'=>$priority,'authorid'=>$USER->id,'timecreated'=>$now,'timemodified'=>$now
            ];
            $id = $DB->insert_record('local_admindash_notes', $rec);
        }
        return ['ok'=>true,'id'=>$id];
    }
}
