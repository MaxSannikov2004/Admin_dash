<?php
defined('MOODLE_INTERNAL') || die();

function xmldb_local_admindash_upgrade($oldversion) {
    global $DB;
    $dbman = $DB->get_manager();

    if ($oldversion < 2025092300) {
        $table = new xmldb_table('local_admindash_notes');
        if (!$dbman->table_exists($table)) {
            $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
            $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('groupid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('itemtype', XMLDB_TYPE_CHAR, '10', null, XMLDB_NOTNULL, null, 'att');
            $table->add_field('itemid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('note', XMLDB_TYPE_TEXT, null, null, null, null, null);
            $table->add_field('authorid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timecreated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_field('timemodified', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, '0');
            $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
            $table->add_index('ix_course_item_user', XMLDB_INDEX_UNIQUE, ['courseid','groupid','userid','itemtype','itemid']);
            $table->add_index('ix_course', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
            $table->add_index('ix_user', XMLDB_INDEX_NOTUNIQUE, ['userid']);
            $dbman->create_table($table);
        }
        upgrade_plugin_savepoint(true, 2025092300, 'local', 'admindash');
    }

    return true;
}
