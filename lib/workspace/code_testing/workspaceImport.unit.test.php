<?php
/**
 * Lightweight unit tests for workspace import helper functions.
 * Run: php lib/workspace/code_testing/workspaceImport.unit.test.php
 */

error_reporting(E_ALL);

if (!defined('TL_WORKSPACE_IMPORT_TEST_MODE')) {
  define('TL_WORKSPACE_IMPORT_TEST_MODE', 1);
}

// Required constants normally provided by TestLink bootstrap.
if (!defined('TL_REQ_STATUS_VALID')) define('TL_REQ_STATUS_VALID', 'V');
if (!defined('TL_REQ_STATUS_NOT_TESTABLE')) define('TL_REQ_STATUS_NOT_TESTABLE', 'N');
if (!defined('TL_REQ_STATUS_DRAFT')) define('TL_REQ_STATUS_DRAFT', 'D');
if (!defined('TL_REQ_STATUS_REVIEW')) define('TL_REQ_STATUS_REVIEW', 'R');
if (!defined('TL_REQ_STATUS_REWORK')) define('TL_REQ_STATUS_REWORK', 'W');
if (!defined('TL_REQ_STATUS_FINISH')) define('TL_REQ_STATUS_FINISH', 'F');
if (!defined('TL_REQ_STATUS_IMPLEMENTED')) define('TL_REQ_STATUS_IMPLEMENTED', 'I');
if (!defined('TL_REQ_STATUS_OBSOLETE')) define('TL_REQ_STATUS_OBSOLETE', 'O');
if (!defined('TL_REQ_TYPE_INFO')) define('TL_REQ_TYPE_INFO', '1');

require_once dirname(__FILE__) . '/../workspaceImport.php';

$__ws_test_failures = array();
$__ws_test_count = 0;

function ws_assert_true($cond, $message)
{
  global $__ws_test_failures, $__ws_test_count;
  $__ws_test_count++;
  if (!$cond) {
    $__ws_test_failures[] = $message;
  }
}

function ws_assert_equals($expected, $actual, $message)
{
  ws_assert_true($expected === $actual, $message . ' (expected=' . var_export($expected, true) . ', actual=' . var_export($actual, true) . ')');
}

function ws_test_append_html_marker()
{
  $a = ws_append_html_marker('', 'TLWS_CASE_KEY', 'TC-1');
  ws_assert_equals('<!-- TLWS_CASE_KEY:TC-1 -->', $a, 'empty preconditions should be replaced with marker');

  $b = ws_append_html_marker('pre', 'TLWS_CASE_KEY', 'TC-1');
  ws_assert_true(strpos($b, "pre\n<!-- TLWS_CASE_KEY:TC-1 -->") === 0, 'marker should be appended to existing content');

  $c = ws_append_html_marker($b, 'TLWS_CASE_KEY', 'TC-1');
  ws_assert_equals($b, $c, 'marker should not be duplicated');
}

function ws_test_new_report_and_issue_tracking()
{
  $report = ws_new_report('dry-run', 7);

  ws_assert_equals('dry-run', $report->mode, 'mode should be stored');
  ws_assert_equals(7, $report->tproject_id, 'project id should be stored');
  ws_assert_true($report->isValid === true, 'new report should be valid');
  ws_assert_equals(0, $report->metrics->requirementsDetected, 'requirementsDetected starts at 0');

  ws_add_issue($report, 'WSP-T1', 'WARN', '/x', 'warn msg');
  ws_assert_true($report->isValid === true, 'warn should keep report valid');
  ws_assert_equals(1, $report->totalsBySeverity['WARN'], 'warn counter should increment');

  ws_add_issue($report, 'WSP-T2', 'ERROR', '/y', 'error msg');
  ws_assert_true($report->isValid === false, 'error should invalidate report');
  ws_assert_equals(1, $report->totalsBySeverity['ERROR'], 'error counter should increment');
}

function ws_test_req_type_validation()
{
  ws_assert_true(ws_req_type_valid('1') === true, 'numeric string > 0 should be accepted');
  ws_assert_true(ws_req_type_valid(2) === true, 'integer > 0 should be accepted');
  ws_assert_true(ws_req_type_valid('0') === false, '0 should be rejected');
  ws_assert_true(ws_req_type_valid('abc') === false, 'non numeric value should be rejected');
}

function ws_test_validate_and_build_model_minimal_valid_xml()
{
  $xml = simplexml_load_string(
    '<tl_workspace schemaVersion="1.0">'
      . '<requirements>'
      . '<requirement_spec specKey="SPEC-1">'
      . '<title>Spec 1</title>'
      . '<description>Scope 1</description>'
      . '<requirement reqKey="REQ-1">'
      . '<docId>DOC-1</docId>'
      . '<title>Requirement 1</title>'
      . '<description>Requirement description</description>'
      . '<status>V</status>'
      . '<type>1</type>'
      . '</requirement>'
      . '</requirement_spec>'
      . '</requirements>'
      . '<testspec>'
      . '<testsuite suiteKey="SUITE-1" name="Suite 1">'
      . '<details>suite details</details>'
      . '<testcase caseKey="TC-1" name="Case 1">'
      . '<summary>Summary 1</summary>'
      . '<preconditions>Precondition 1</preconditions>'
      . '<execution_type>1</execution_type>'
      . '<importance>2</importance>'
      . '<steps>'
      . '<step step_number="1"><actions>A1</actions><expectedresults>E1</expectedresults></step>'
      . '</steps>'
      . '<requirement_links><requirement_ref reqKey="REQ-1"/></requirement_links>'
      . '</testcase>'
      . '</testsuite>'
      . '</testspec>'
      . '</tl_workspace>'
  );

  $report = ws_new_report('dry-run', 1);
  $model = ws_validate_and_build_model($xml, $report);

  ws_assert_true($model !== null, 'model should be created for valid XML');
  ws_assert_true($report->isValid === true, 'report should remain valid');
  ws_assert_equals(1, $report->metrics->requirementsDetected, 'one requirement should be detected');
  ws_assert_equals(1, $report->metrics->suitesDetected, 'one suite should be detected');
  ws_assert_equals(1, $report->metrics->testcasesDetected, 'one testcase should be detected');
  ws_assert_equals(1, $report->metrics->traceLinksDetected, 'one trace link should be detected');
}

function ws_test_validate_and_build_model_detects_missing_req_key_reference()
{
  $xml = simplexml_load_string(
    '<tl_workspace schemaVersion="1.0">'
      . '<requirements>'
      . '<requirement_spec specKey="SPEC-1">'
      . '<title>Spec 1</title>'
      . '<requirement reqKey="REQ-1">'
      . '<docId>DOC-1</docId>'
      . '<title>Requirement 1</title>'
      . '<status>V</status>'
      . '<type>1</type>'
      . '</requirement>'
      . '</requirement_spec>'
      . '</requirements>'
      . '<testspec>'
      . '<testsuite suiteKey="SUITE-1" name="Suite 1">'
      . '<testcase caseKey="TC-1" name="Case 1">'
      . '<summary>Summary 1</summary>'
      . '<execution_type>1</execution_type>'
      . '<importance>2</importance>'
      . '<steps>'
      . '<step step_number="1"><actions>A1</actions><expectedresults>E1</expectedresults></step>'
      . '</steps>'
      . '<requirement_links><requirement_ref reqKey="REQ-404"/></requirement_links>'
      . '</testcase>'
      . '</testsuite>'
      . '</testspec>'
      . '</tl_workspace>'
  );

  $report = ws_new_report('dry-run', 1);
  $model = ws_validate_and_build_model($xml, $report);

  ws_assert_true($model === null, 'invalid XML should not produce a model');
  ws_assert_true($report->isValid === false, 'report should be invalid on bad requirement_ref');
  ws_assert_equals(1, $report->totalsBySeverity['ERROR'], 'should register one error');
  ws_assert_equals('TR5-002', $report->issues[0]['code'], 'error code should match missing reqKey reference');
}

ws_test_append_html_marker();
ws_test_new_report_and_issue_tracking();
ws_test_req_type_validation();
ws_test_validate_and_build_model_minimal_valid_xml();
ws_test_validate_and_build_model_detects_missing_req_key_reference();

if (count($__ws_test_failures) > 0) {
  echo "FAILED: " . count($__ws_test_failures) . " of " . $__ws_test_count . " assertions failed.\n";
  foreach ($__ws_test_failures as $failure) {
    echo " - " . $failure . "\n";
  }
  exit(1);
}

echo "OK: " . $__ws_test_count . " assertions passed.\n";
exit(0);
