<?php
defined('MOODLE_INTERNAL') || die();
if ($hassiteconfig) {
    $ADMIN->add('reports', new admin_externalpage('local_admindash',
        get_string('pluginname', 'local_admindash'),
        new moodle_url('/local/admindash/index.php')));
}
