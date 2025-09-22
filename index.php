<?php
require_once(__DIR__.'/../../config.php');
require_login();
$context = context_system::instance();
require_capability('local/admindash:view', $context);

$PAGE->set_url(new moodle_url('/local/admindash/index.php'));
$PAGE->set_context($context);
$PAGE->set_title(get_string('pluginname', 'local_admindash'));
$PAGE->set_heading(get_string('pluginname', 'local_admindash'));
$PAGE->set_pagelayout('report');
$PAGE->set_cacheable(false);
$PAGE->requires->css(new moodle_url('/local/admindash/styles.css'));
$PAGE->requires->string_for_js('student', 'core');
$PAGE->requires->string_for_js('no_data', 'local_admindash');
$PAGE->requires->string_for_js('element', 'local_admindash');
$PAGE->requires->string_for_js('loading', 'local_admindash');
$PAGE->requires->string_for_js('apply', 'local_admindash');
$PAGE->requires->string_for_js('note_editor_title', 'local_admindash');
$PAGE->requires->string_for_js('save', 'local_admindash');
$PAGE->requires->string_for_js('cancel', 'local_admindash');
$PAGE->requires->jquery();
$PAGE->requires->js(new moodle_url('/local/admindash/app.js'));

echo $OUTPUT->header();
?>
<div class="cd-curator">
  <div class="cd-filters cd-card">
    <div class="row">
      <label><?php echo get_string('filter_category', 'local_admindash'); ?></label>
      <select id="cd-category"></select>
    </div>
    <div class="row">
      <label><?php echo get_string('filter_course', 'local_admindash'); ?></label>
      <select id="cd-course"></select>
      <label><?php echo get_string('filter_group', 'local_admindash'); ?></label>
      <select id="cd-group"></select>
    </div>
    <div class="row">
      <label><?php echo get_string('filter_from', 'local_admindash'); ?></label>
      <input type="date" id="cd-from"/>
      <label><?php echo get_string('filter_to', 'local_admindash'); ?></label>
      <input type="date" id="cd-to"/>
      <button id="cd-apply" class="btn"><?php echo get_string('apply', 'local_admindash'); ?></button>
    </div>
  </div>

  <div class="cd-tabs">
    <div class="cd-tab active" data-tab="grades"><?php echo get_string('tab_grades', 'local_admindash'); ?></div>
    <div class="cd-tab" data-tab="attendance"><?php echo get_string('tab_attendance', 'local_admindash'); ?></div>
  </div>

  <div class="cd-kpis">
    <div class="kpi"><span id="kpi-grade-avg">0%</span><small><?php echo get_string('kpi_grade_avg', 'local_admindash'); ?></small></div>
    <div class="kpi"><span id="kpi-att">0%</span><small><?php echo get_string('kpi_attendance', 'local_admindash'); ?></small></div>
    <div class="kpi"><span id="kpi-students">0</span><small><?php echo get_string('kpi_students', 'local_admindash'); ?></small></div>
  </div>

  <div class="cd-tabpanel active" id="tab-grades">
    <section class="cd-card"><h3><?php echo get_string('grades_by_group', 'local_admindash'); ?></h3><div class="cd-scrollx"><div id="cd-grades"></div></div></section>
  </div>
  <div class="cd-tabpanel" id="tab-attendance">
    <section class="cd-card"><h3><?php echo get_string('attendance_by_group', 'local_admindash'); ?></h3><div class="cd-scrollx"><div id="cd-attendance"></div></div></section>
  </div>
</div>
<?php echo $OUTPUT->footer();
