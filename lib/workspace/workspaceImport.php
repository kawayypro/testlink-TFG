<?php
/**
 * Workspace XML import (requirements + testspec + traceability)
 * Contract v1.0
 */
if (!defined('TL_WORKSPACE_IMPORT_TEST_MODE')) {
  require('../../config.inc.php');
  require_once('common.php');
  require_once('xml.inc.php');

  testlinkInitPage($db,false,false,'checkRights');

  $args = ws_init_args();
  $report = null;

  if ($args->doUpload) {
    $report = ws_handle_upload_and_process($db, $args);
  }

  ws_render_page($args, $report);
}


function checkRights(&$db,&$user)
{
  $tprojectID = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
  $canTC = $user->hasRight($db,'mgt_modify_tc',$tprojectID) == 'yes';
  $canReq = $user->hasRight($db,'mgt_modify_req',$tprojectID) == 'yes';
  $canLink = $user->hasRight($db,'req_tcase_link_management',$tprojectID) == 'yes';
  return ($canTC && $canReq && $canLink);
}


function ws_init_args()
{
  $args = new stdClass();
  $request = strings_stripSlashes($_REQUEST);

  $args->doUpload = isset($request['UploadFile']) ? 1 : 0;
  $args->mode = isset($request['mode']) ? trim($request['mode']) : 'dry-run';
  if ($args->mode !== 'execute') {
    $args->mode = 'dry-run';
  }

  $args->tproject_id = isset($_SESSION['testprojectID']) ? intval($_SESSION['testprojectID']) : 0;
  $args->tproject_name = isset($_SESSION['testprojectName']) ? $_SESSION['testprojectName'] : '';
  $args->user_id = isset($_SESSION['userID']) ? intval($_SESSION['userID']) : 0;

  return $args;
}


function ws_handle_upload_and_process(&$db, $args)
{
  $report = ws_new_report($args->mode, $args->tproject_id);
  $maxBytes = config_get('import_file_max_size_bytes');

  if (!isset($_FILES['uploadedFile'])) {
    ws_add_issue($report, 'WSP-050', 'ERROR', '/', 'No uploaded file payload found.');
    return $report;
  }

  $fInfo = $_FILES['uploadedFile'];
  $source = isset($fInfo['tmp_name']) ? $fInfo['tmp_name'] : null;

  if (($source == 'none') || ($source == '') || !is_uploaded_file($source)) {
    ws_add_issue($report, 'WSP-051', 'ERROR', '/', lang_get('please_choose_file_to_import'));
    return $report;
  }

  if (intval($fInfo['size']) > intval($maxBytes)) {
    ws_add_issue($report, 'WSP-052', 'ERROR', '/', sprintf(lang_get('file_size_exceeded'),$fInfo['size'],$maxBytes));
    return $report;
  }

  $dest = TL_TEMP_PATH . session_id() . '-workspace-import.xml';
  if (!move_uploaded_file($source, $dest)) {
    ws_add_issue($report, 'WSP-053', 'ERROR', '/', 'Could not move uploaded file to temp folder.');
    return $report;
  }

  $xml = @simplexml_load_file_wrapper($dest);
  if ($xml === FALSE) {
    ws_add_issue($report, 'WSP-005', 'ERROR', '/', 'XML is not well formed or could not be parsed.');
    @unlink($dest);
    return $report;
  }

  $model = ws_validate_and_build_model($xml, $report);
  if ($model === null || !$report->isValid) {
    @unlink($dest);
    return $report;
  }

  if ($args->mode === 'dry-run') {
    $report->status = 'validated';
    @unlink($dest);
    return $report;
  }

  ws_execute_import($db, $args, $model, $report);
  @unlink($dest);
  return $report;
}


function ws_validate_and_build_model($xml, &$report)
{
  $rootName = ws_local_name($xml);
  if ($rootName !== 'tl_workspace') {
    ws_add_issue($report, 'WSP-001', 'ERROR', '/tl_workspace', 'Root element must be tl_workspace.');
    return null;
  }

  $schemaVersion = ws_attr($xml, 'schemaVersion');
  if ($schemaVersion !== '1.0') {
    ws_add_issue($report, 'WSP-001', 'ERROR', '/tl_workspace/@schemaVersion', 'Unsupported schemaVersion. Expected 1.0.');
    return null;
  }

  $report->schemaVersion = $schemaVersion;

  $reqSection = ws_first_child($xml, 'requirements');
  $testspecSection = ws_first_child($xml, 'testspec');

  if (!$reqSection) {
    ws_add_issue($report, 'REQ-001', 'ERROR', '/tl_workspace/requirements', 'Missing requirements section.');
  }
  if (!$testspecSection) {
    ws_add_issue($report, 'TS-001', 'ERROR', '/tl_workspace/testspec', 'Missing testspec section.');
  }
  if (!$report->isValid) {
    return null;
  }

  $model = new stdClass();
  $model->requirements = array();
  $model->suites = array();
  $model->testcases = array();

  $seenSpecKeys = array();
  $seenReqKeys = array();
  $seenDocIds = array();
  $seenSuiteKeys = array();
  $seenCaseKeys = array();

  // Requirements
  $specNodes = ws_children($reqSection, 'requirement_spec');
  if (count($specNodes) == 0) {
    ws_add_issue($report, 'REQ-001', 'ERROR', '/tl_workspace/requirements', 'At least one requirement_spec is required.');
  }

  foreach ($specNodes as $sIdx => $specNode) {
    $path = '/tl_workspace/requirements/requirement_spec[' . ($sIdx + 1) . ']';
    $specKey = trim(ws_attr($specNode, 'specKey'));
    $specTitle = trim(ws_child_text($specNode, 'title', ''));
    $specScope = ws_child_text($specNode, 'description', '');

    if ($specKey === '') {
      ws_add_issue($report, 'RQ3-001', 'ERROR', $path . '/@specKey', 'specKey is required.');
      continue;
    }
    if (isset($seenSpecKeys[$specKey])) {
      ws_add_issue($report, 'RQ3-002', 'ERROR', $path . '/@specKey', 'Duplicated specKey: ' . $specKey);
      continue;
    }
    $seenSpecKeys[$specKey] = true;

    if ($specTitle === '') {
      $specTitle = $specKey;
    }

    $spec = new stdClass();
    $spec->specKey = $specKey;
    $spec->title = $specTitle;
    $spec->scope = $specScope;
    $spec->requirements = array();

    $reqNodes = ws_children($specNode, 'requirement');
    foreach ($reqNodes as $rIdx => $reqNode) {
      $rPath = $path . '/requirement[' . ($rIdx + 1) . ']';
      $reqKey = trim(ws_attr($reqNode, 'reqKey'));
      $docId = trim(ws_child_text($reqNode, 'docId', ''));
      $title = trim(ws_child_text($reqNode, 'title', ''));
      $desc = ws_child_text($reqNode, 'description', '');
      $status = strtoupper(trim(ws_child_text($reqNode, 'status', TL_REQ_STATUS_VALID)));
      $type = trim(ws_child_text($reqNode, 'type', TL_REQ_TYPE_INFO));

      if ($reqKey === '') {
        ws_add_issue($report, 'RQ3-003', 'ERROR', $rPath . '/@reqKey', 'reqKey is required.');
        continue;
      }
      if (isset($seenReqKeys[$reqKey])) {
        ws_add_issue($report, 'RQ3-005', 'ERROR', $rPath . '/@reqKey', 'Duplicated reqKey: ' . $reqKey);
        continue;
      }
      $seenReqKeys[$reqKey] = true;

      if ($docId === '') {
        ws_add_issue($report, 'RQ3-004', 'ERROR', $rPath . '/docId', 'docId is required.');
        continue;
      }
      if (isset($seenDocIds[$docId])) {
        ws_add_issue($report, 'RQ3-006', 'ERROR', $rPath . '/docId', 'Duplicated docId: ' . $docId);
        continue;
      }
      $seenDocIds[$docId] = true;

      if ($title === '') {
        ws_add_issue($report, 'REQ-004', 'ERROR', $rPath . '/title', 'Requirement title is required.');
        continue;
      }

      if (!ws_req_status_valid($status)) {
        ws_add_issue($report, 'RQ3-008', 'ERROR', $rPath . '/status', 'Invalid requirement status: ' . $status);
        continue;
      }

      if (!ws_req_type_valid($type)) {
        ws_add_issue($report, 'RQ3-009', 'ERROR', $rPath . '/type', 'Invalid requirement type: ' . $type);
        continue;
      }

      $req = new stdClass();
      $req->reqKey = $reqKey;
      $req->docId = $docId;
      $req->title = $title;
      $req->description = $desc;
      $req->status = $status;
      $req->type = $type;
      $spec->requirements[] = $req;
      $report->metrics->requirementsDetected++;
    }

    $model->requirements[] = $spec;
  }

  // Testspec
  $suiteNodes = ws_children($testspecSection, 'testsuite');
  if (count($suiteNodes) == 0) {
    ws_add_issue($report, 'TS-001', 'ERROR', '/tl_workspace/testspec', 'At least one root testsuite is required.');
  }

  foreach ($suiteNodes as $idx => $suiteNode) {
    $suite = ws_collect_suite($suiteNode,
      '/tl_workspace/testspec/testsuite[' . ($idx + 1) . ']',
      $report,
      $seenSuiteKeys,
      $seenCaseKeys,
      $seenReqKeys,
      $model
    );

    if ($suite !== null) {
      $model->suites[] = $suite;
    }
  }

  return $report->isValid ? $model : null;
}


function ws_collect_suite($suiteNode, $path, &$report, &$seenSuiteKeys, &$seenCaseKeys, &$seenReqKeys, &$model)
{
  $suiteKey = trim(ws_attr($suiteNode, 'suiteKey'));
  $name = trim(ws_attr($suiteNode, 'name'));
  if ($name === '') {
    $name = trim(ws_child_text($suiteNode, 'name', ''));
  }
  $details = ws_child_text($suiteNode, 'details', '');

  if ($suiteKey === '') {
    ws_add_issue($report, 'TS4-001', 'ERROR', $path . '/@suiteKey', 'suiteKey is required.');
    return null;
  }

  if (isset($seenSuiteKeys[$suiteKey])) {
    ws_add_issue($report, 'TS4-002', 'ERROR', $path . '/@suiteKey', 'Duplicated suiteKey: ' . $suiteKey);
    return null;
  }
  $seenSuiteKeys[$suiteKey] = true;

  if ($name === '') {
    ws_add_issue($report, 'TS4-003', 'ERROR', $path . '/@name', 'testsuite name is required.');
    return null;
  }

  $suite = new stdClass();
  $suite->suiteKey = $suiteKey;
  $suite->name = $name;
  $suite->details = $details;
  $suite->childrenSuites = array();
  $suite->testcases = array();

  $report->metrics->suitesDetected++;

  $childSuites = ws_children($suiteNode, 'testsuite');
  foreach ($childSuites as $cIdx => $childSuiteNode) {
    $childPath = $path . '/testsuite[' . ($cIdx + 1) . ']';
    $childSuite = ws_collect_suite($childSuiteNode, $childPath, $report, $seenSuiteKeys, $seenCaseKeys, $seenReqKeys, $model);
    if ($childSuite !== null) {
      $suite->childrenSuites[] = $childSuite;
    }
  }

  $tcaseNodes = ws_children($suiteNode, 'testcase');
  foreach ($tcaseNodes as $tIdx => $tcaseNode) {
    $tPath = $path . '/testcase[' . ($tIdx + 1) . ']';

    $caseKey = trim(ws_attr($tcaseNode, 'caseKey'));
    $tcName = trim(ws_attr($tcaseNode, 'name'));
    if ($tcName === '') {
      $tcName = trim(ws_child_text($tcaseNode, 'name', ''));
    }

    $summary = ws_child_text($tcaseNode, 'summary', '');
    $preconditions = ws_child_text($tcaseNode, 'preconditions', '');
    $executionType = intval(ws_child_text($tcaseNode, 'execution_type', 1));
    $importance = intval(ws_child_text($tcaseNode, 'importance', 2));

    if ($caseKey === '') {
      ws_add_issue($report, 'TC4-001', 'ERROR', $tPath . '/@caseKey', 'caseKey is required.');
      continue;
    }
    if (isset($seenCaseKeys[$caseKey])) {
      ws_add_issue($report, 'TC4-002', 'ERROR', $tPath . '/@caseKey', 'Duplicated caseKey: ' . $caseKey);
      continue;
    }
    $seenCaseKeys[$caseKey] = true;

    if ($tcName === '') {
      ws_add_issue($report, 'TC4-003', 'ERROR', $tPath . '/@name', 'testcase name is required.');
      continue;
    }

    if (trim($summary) === '') {
      ws_add_issue($report, 'TC4-004', 'ERROR', $tPath . '/summary', 'summary is required.');
      continue;
    }

    if (!in_array($executionType, array(1,2), true)) {
      ws_add_issue($report, 'TC4-005', 'ERROR', $tPath . '/execution_type', 'execution_type must be 1 or 2.');
      continue;
    }

    if (!in_array($importance, array(1,2,3), true)) {
      ws_add_issue($report, 'TC4-006', 'ERROR', $tPath . '/importance', 'importance must be 1, 2 or 3.');
      continue;
    }

    $stepsNode = ws_first_child($tcaseNode, 'steps');
    if (!$stepsNode) {
      ws_add_issue($report, 'ST4-001', 'ERROR', $tPath . '/steps', 'steps section is required.');
      continue;
    }

    $stepNodes = ws_children($stepsNode, 'step');
    if (count($stepNodes) == 0) {
      ws_add_issue($report, 'ST4-001', 'ERROR', $tPath . '/steps', 'At least one step is required.');
      continue;
    }

    $seenStepNumbers = array();
    $steps = array();
    foreach ($stepNodes as $sIdx => $stepNode) {
      $sPath = $tPath . '/steps/step[' . ($sIdx + 1) . ']';
      $stepNumber = intval(ws_attr($stepNode, 'step_number'));
      if ($stepNumber <= 0) {
        $stepNumber = intval(ws_child_text($stepNode, 'step_number', 0));
      }
      $actions = ws_child_text($stepNode, 'actions', '');
      $expected = ws_child_text($stepNode, 'expectedresults', '');
      if ($expected === '') {
        $expected = ws_child_text($stepNode, 'expected_results', '');
      }

      if ($stepNumber <= 0) {
        ws_add_issue($report, 'ST4-002', 'ERROR', $sPath, 'step_number is required and must be > 0.');
        continue 2;
      }
      if (isset($seenStepNumbers[$stepNumber])) {
        ws_add_issue($report, 'ST4-003', 'ERROR', $sPath, 'Duplicated step_number in testcase: ' . $stepNumber);
        continue 2;
      }
      $seenStepNumbers[$stepNumber] = true;

      if (trim($actions) === '') {
        ws_add_issue($report, 'ST4-004', 'ERROR', $sPath . '/actions', 'actions is required.');
        continue 2;
      }
      if (trim($expected) === '') {
        ws_add_issue($report, 'ST4-005', 'ERROR', $sPath . '/expectedresults', 'expectedresults is required.');
        continue 2;
      }

      $steps[] = array(
        'step_number' => $stepNumber,
        'actions' => $actions,
        'expected_results' => $expected,
        'execution_type' => $executionType
      );
    }

    $reqLinks = array();
    $rLinksNode = ws_first_child($tcaseNode, 'requirement_links');
    if ($rLinksNode) {
      $refNodes = ws_children($rLinksNode, 'requirement_ref');
      $seenInCase = array();
      foreach ($refNodes as $rIdx => $refNode) {
        $rPath = $tPath . '/requirement_links/requirement_ref[' . ($rIdx + 1) . ']';
        $reqKey = trim(ws_attr($refNode, 'reqKey'));
        if ($reqKey === '') {
          ws_add_issue($report, 'TR5-001', 'ERROR', $rPath . '/@reqKey', 'reqKey is required in requirement_ref.');
          continue;
        }
        if (!isset($seenReqKeys[$reqKey])) {
          ws_add_issue($report, 'TR5-002', 'ERROR', $rPath . '/@reqKey', 'Referenced reqKey does not exist in requirements section: ' . $reqKey);
          continue;
        }
        if (isset($seenInCase[$reqKey])) {
          ws_add_issue($report, 'TR5-004', 'WARN', $rPath . '/@reqKey', 'Duplicated reqKey in same testcase, duplicate will be ignored: ' . $reqKey);
          continue;
        }
        $seenInCase[$reqKey] = true;
        $reqLinks[] = $reqKey;
      }
    }

    $tc = new stdClass();
    $tc->caseKey = $caseKey;
    $tc->name = $tcName;
    $tc->summary = $summary;
    $tc->preconditions = $preconditions;
    $tc->executionType = $executionType;
    $tc->importance = $importance;
    $tc->steps = $steps;
    $tc->requirementRefs = $reqLinks;

    $suite->testcases[] = $tc;
    $model->testcases[] = $tc;
    $report->metrics->testcasesDetected++;
    $report->metrics->traceLinksDetected += count($reqLinks);
  }

  return $suite;
}


function ws_execute_import(&$db, $args, $model, &$report)
{
  $reqSpecMgr = new requirement_spec_mgr($db);
  $reqMgr = new requirement_mgr($db);
  $tsuiteMgr = new testsuite($db);
  $tcaseMgr = new testcase($db);
  $treeMgr = new tree($db);

  $reqKeyToId = array();
  $caseKeyToId = array();

  // Phase 3: Requirements
  foreach ($model->requirements as $spec) {
    $specId = ws_upsert_requirement_spec($reqSpecMgr, $args->tproject_id, $args->user_id, $spec, $report);
    if ($specId <= 0) {
      continue;
    }

    foreach ($spec->requirements as $req) {
      $reqId = ws_upsert_requirement($reqMgr, $args->tproject_id, $specId, $args->user_id, $req, $report);
      if ($reqId > 0) {
        $reqKeyToId[$req->reqKey] = $reqId;
      }
    }
  }

  // Phase 4: Testspec
  $nodeTypes = $treeMgr->get_available_node_types();
  foreach ($model->suites as $suite) {
    ws_upsert_suite_recursive($db, $treeMgr, $tsuiteMgr, $tcaseMgr, $args->tproject_id, $args->user_id,
                              $nodeTypes, $suite, $args->tproject_id, $caseKeyToId, $report);
  }

  // Phase 5: Traceability
  foreach ($model->testcases as $tc) {
    if (!isset($caseKeyToId[$tc->caseKey])) {
      ws_add_issue($report, 'TR5-003', 'ERROR', '/traceability/' . $tc->caseKey, 'Could not resolve testcase for caseKey: ' . $tc->caseKey);
      continue;
    }

    $tcId = $caseKeyToId[$tc->caseKey];
    foreach ($tc->requirementRefs as $reqKey) {
      if (!isset($reqKeyToId[$reqKey])) {
        ws_add_issue($report, 'TR5-002', 'ERROR', '/traceability/' . $tc->caseKey . '/' . $reqKey,
                     'Could not resolve requirement for reqKey: ' . $reqKey);
        $report->metrics->traceabilityFailed++;
        continue;
      }

      $op = $reqMgr->assignToTCaseUsingLatestVersions($reqKeyToId[$reqKey], $tcId, $args->user_id);
      $ok = false;
      $msg = '';

      if (is_array($op)) {
        $ok = isset($op['status_ok']) ? (bool)$op['status_ok'] : false;
        $msg = isset($op['msg']) ? trim($op['msg']) : '';
      } else {
        // requirement_mgr::assignToTCaseUsingLatestVersions returns 1/0 on this TestLink version.
        $ok = (intval($op) === 1);
      }

      if ($ok) {
        $report->metrics->traceabilityLinked++;
      } else {
        $report->metrics->traceabilityFailed++;
        if ($msg === '') {
          $msg = 'Link API returned failure (0). Check testcase latest active version and requirement latest version availability.';
        }
        ws_add_issue($report, 'TR5-005', 'ERROR', '/traceability/' . $tc->caseKey . '/' . $reqKey,
                     'Failed linking requirement to testcase: ' . $msg);
      }
    }
  }

  if ($report->isValid) {
    $report->status = 'success';
  } else {
    $report->status = ($report->metrics->requirementsCreated +
      $report->metrics->requirementsUpdated +
      $report->metrics->suitesCreated +
      $report->metrics->suitesUpdated +
      $report->metrics->testcasesCreated +
      $report->metrics->testcasesUpdated +
      $report->metrics->traceabilityLinked) > 0 ? 'partial-success' : 'failed';
  }
}


function ws_upsert_requirement_spec(&$reqSpecMgr, $tprojectId, $userId, $spec, &$report)
{
  $existing = $reqSpecMgr->getByDocID($spec->specKey, $tprojectId, null, array('access_key' => 'id'));
  if (!is_null($existing) && count($existing) > 0) {
    $specId = intval(array_keys($existing)[0]);
    $item = array(
      'id' => $specId,
      'doc_id' => $spec->specKey,
      'name' => $spec->title,
      'scope' => $spec->scope,
      'total_req' => count($spec->requirements),
      'modifier_id' => $userId,
      'type' => TL_REQ_SPEC_TYPE_USER_REQ_SPEC,
      'node_order' => 1
    );

    $op = $reqSpecMgr->update($item, array('create_rev' => false));
    if ($op['status_ok']) {
      $report->metrics->requirementSpecsUpdated++;
      return $specId;
    }

    ws_add_issue($report, 'RQ3-011', 'ERROR', '/requirements/' . $spec->specKey,
                 'Failed to update requirement_spec: ' . $op['msg']);
    return -1;
  }

  // Recovery path: if a previous failed import created req_specs row without revision,
  // getByDocID() can miss it because it joins req_specs_revisions.
  $orphanSpecId = ws_find_reqspec_id_by_docid_raw($reqSpecMgr->db, $spec->specKey, $tprojectId);
  if ($orphanSpecId > 0) {
    if (!ws_ensure_reqspec_has_revision($reqSpecMgr, $orphanSpecId, $spec, $userId, $report)) {
      return -1;
    }

    $item = array(
      'id' => $orphanSpecId,
      'doc_id' => $spec->specKey,
      'name' => $spec->title,
      'scope' => $spec->scope,
      'total_req' => count($spec->requirements),
      'modifier_id' => $userId,
      'type' => TL_REQ_SPEC_TYPE_USER_REQ_SPEC,
      'node_order' => 1
    );

    $op = $reqSpecMgr->update($item, array('create_rev' => false));
    if ($op['status_ok']) {
      $report->metrics->requirementSpecsUpdated++;
      return $orphanSpecId;
    }

    ws_add_issue($report, 'RQ3-011', 'ERROR', '/requirements/' . $spec->specKey,
                 'Found existing orphan requirement_spec but update failed: ' . $op['msg']);
    return -1;
  }

  $op = $reqSpecMgr->create($tprojectId, $tprojectId, $spec->specKey, $spec->title,
                            $spec->scope, count($spec->requirements), $userId,
                            TL_REQ_SPEC_TYPE_USER_REQ_SPEC);
  if ($op['status_ok']) {
    $report->metrics->requirementSpecsCreated++;
    return intval($op['id']);
  }

  ws_add_issue($report, 'RQ3-011', 'ERROR', '/requirements/' . $spec->specKey,
               'Failed to create requirement_spec: ' . $op['msg']);
  return -1;
}


function ws_upsert_requirement(&$reqMgr, $tprojectId, $specId, $userId, $req, &$report)
{
  $existing = $reqMgr->getByDocID($req->docId, $tprojectId, null, array('access_key' => 'id', 'output' => 'minimun'));

  if (!is_null($existing) && count($existing) > 0) {
    $reqId = intval(array_keys($existing)[0]);
    $item = current($existing);

    if (isset($item['srs_id']) && intval($item['srs_id']) !== intval($specId)) {
      ws_add_issue($report, 'RQ3-007', 'ERROR', '/requirements/' . $req->reqKey,
                   'Requirement exists in another requirement_spec and move is not allowed in v1.');
      return -1;
    }

    $last = $reqMgr->get_last_version_info($reqId, array('output' => 'id,version'));
    if (is_null($last) || !isset($last['id'])) {
      ws_add_issue($report, 'RQ3-014', 'ERROR', '/requirements/' . $req->reqKey,
                   'Could not resolve latest requirement version for update.');
      return -1;
    }

    $op = $reqMgr->update($reqId, intval($last['id']), $req->docId, $req->title,
                          $req->description, $userId, $req->status, $req->type,
                          1, 0, $tprojectId, 0, false);
    if ($op['status_ok']) {
      $report->metrics->requirementsUpdated++;
      return $reqId;
    }

    ws_add_issue($report, 'RQ3-012', 'ERROR', '/requirements/' . $req->reqKey,
                 'Failed to update requirement: ' . $op['msg']);
    return -1;
  }

  $op = $reqMgr->create($specId, $req->docId, $req->title, $req->description,
                        $userId, $req->status, $req->type, 1, 0, $tprojectId);

  if ($op['status_ok']) {
    $report->metrics->requirementsCreated++;
    return intval($op['id']);
  }

  ws_add_issue($report, 'RQ3-012', 'ERROR', '/requirements/' . $req->reqKey,
               'Failed to create requirement: ' . $op['msg']);
  return -1;
}


function ws_upsert_suite_recursive(&$db, &$treeMgr, &$tsuiteMgr, &$tcaseMgr, $tprojectId, $userId,
                                   $nodeTypes, $suite, $parentId, &$caseKeyToId, &$report)
{
  $suiteId = ws_find_suite_id_by_key($db, $treeMgr, $suite->suiteKey, $tprojectId);
  $details = ws_append_html_marker($suite->details, 'TLWS_SUITE_KEY', $suite->suiteKey);

  if ($suiteId > 0) {
    $op = $tsuiteMgr->update($suiteId, $suite->name, $details, $parentId, null);
    if ($op['status_ok']) {
      $report->metrics->suitesUpdated++;
    } else {
      ws_add_issue($report, 'TS4-004', 'ERROR', '/testsuite/' . $suite->suiteKey,
                   'Failed to update testsuite: ' . $op['msg']);
      return;
    }
  } else {
    $existingByName = $tsuiteMgr->get_by_name($suite->name, $parentId, array('output' => 'minimun'));
    if (!is_null($existingByName) && count($existingByName) > 0) {
      $suiteId = intval($existingByName[0]['id']);
      $op = $tsuiteMgr->update($suiteId, $suite->name, $details, $parentId, null);
      if ($op['status_ok']) {
        $report->metrics->suitesUpdated++;
      } else {
        ws_add_issue($report, 'TS4-004', 'ERROR', '/testsuite/' . $suite->suiteKey,
                     'Failed to update testsuite by name: ' . $op['msg']);
        return;
      }
    } else {
      $op = $tsuiteMgr->create($parentId, $suite->name, $details, null, 0, 'allow_repeat');
      if ($op['status_ok']) {
        $suiteId = intval($op['id']);
        $report->metrics->suitesCreated++;
      } else {
        ws_add_issue($report, 'TS4-004', 'ERROR', '/testsuite/' . $suite->suiteKey,
                     'Failed to create testsuite: ' . $op['msg']);
        return;
      }
    }
  }

  foreach ($suite->testcases as $tc) {
    ws_upsert_testcase($db, $treeMgr, $tcaseMgr, $nodeTypes, $tprojectId, $userId, $suiteId,
                       $tc, $caseKeyToId, $report);
  }

  foreach ($suite->childrenSuites as $childSuite) {
    ws_upsert_suite_recursive($db, $treeMgr, $tsuiteMgr, $tcaseMgr, $tprojectId, $userId,
                              $nodeTypes, $childSuite, $suiteId, $caseKeyToId, $report);
  }
}


function ws_upsert_testcase(&$db, &$treeMgr, &$tcaseMgr, $nodeTypes, $tprojectId, $userId, $suiteId,
                            $tc, &$caseKeyToId, &$report)
{
  $markerPreconditions = ws_append_html_marker($tc->preconditions, 'TLWS_CASE_KEY', $tc->caseKey);

  $found = ws_find_testcase_by_key($db, $treeMgr, $tc->caseKey, $tprojectId);
  $tcId = 0;
  $tcVersionId = 0;

  if (!is_null($found)) {
    $tcId = intval($found['testcase_id']);
    $tcVersionId = intval($found['tcversion_id']);

    $op = $tcaseMgr->update($tcId, $tcVersionId, $tc->name, $tc->summary,
                            $markerPreconditions, $tc->steps, $userId, '',
                            testcase::DEFAULT_ORDER, $tc->executionType, $tc->importance);
    if ($op['status_ok']) {
      $report->metrics->testcasesUpdated++;
    } else {
      ws_add_issue($report, 'TC4-010', 'ERROR', '/testcase/' . $tc->caseKey,
                   'Failed to update testcase: ' . $op['msg']);
      return;
    }
  } else {
    $existingByName = ws_find_testcase_by_name_under_suite($db, $nodeTypes, $suiteId, $tc->name);
    if ($existingByName > 0) {
      $last = $tcaseMgr->get_last_version_info($existingByName, array('output' => 'minimun'));
      if (!is_null($last) && isset($last['tcversion_id'])) {
        $tcId = $existingByName;
        $tcVersionId = intval($last['tcversion_id']);
        $op = $tcaseMgr->update($tcId, $tcVersionId, $tc->name, $tc->summary,
                                $markerPreconditions, $tc->steps, $userId, '',
                                testcase::DEFAULT_ORDER, $tc->executionType, $tc->importance);
        if ($op['status_ok']) {
          $report->metrics->testcasesUpdated++;
        } else {
          ws_add_issue($report, 'TC4-010', 'ERROR', '/testcase/' . $tc->caseKey,
                       'Failed to update testcase by name: ' . $op['msg']);
          return;
        }
      } else {
        $op = $tcaseMgr->create($suiteId, $tc->name, $tc->summary, $markerPreconditions,
                                $tc->steps, $userId, '', testcase::DEFAULT_ORDER,
                                testcase::AUTOMATIC_ID, $tc->executionType, $tc->importance,
                                array('check_duplicate_name' => testcase::DONT_CHECK_DUPLICATE_NAME,
                                      'action_on_duplicate_name' => 'generate_new'));
        if ($op['status_ok']) {
          $tcId = intval($op['id']);
          $tcVersionId = intval($op['tcversion_id']);
          $report->metrics->testcasesCreated++;
        } else {
          ws_add_issue($report, 'TC4-010', 'ERROR', '/testcase/' . $tc->caseKey,
                       'Failed to create testcase after unresolved latest version by name: ' . $op['msg']);
          return;
        }
      }
    } else {
      $op = $tcaseMgr->create($suiteId, $tc->name, $tc->summary, $markerPreconditions,
                              $tc->steps, $userId, '', testcase::DEFAULT_ORDER,
                              testcase::AUTOMATIC_ID, $tc->executionType, $tc->importance,
                              array('check_duplicate_name' => testcase::DONT_CHECK_DUPLICATE_NAME,
                                    'action_on_duplicate_name' => 'generate_new'));
      if ($op['status_ok']) {
        $tcId = intval($op['id']);
        $tcVersionId = intval($op['tcversion_id']);
        $report->metrics->testcasesCreated++;
      } else {
        ws_add_issue($report, 'TC4-010', 'ERROR', '/testcase/' . $tc->caseKey,
                     'Failed to create testcase: ' . $op['msg']);
        return;
      }
    }
  }

  if ($tcId > 0) {
    $caseKeyToId[$tc->caseKey] = $tcId;
  }
}


function ws_find_suite_id_by_key(&$db, &$treeMgr, $suiteKey, $tprojectId)
{
  $tables = tlObjectWithDB::getDBTables(array('testsuites','nodes_hierarchy'));
  $needle = $db->prepare_string('TLWS_SUITE_KEY:' . $suiteKey);

  $sql = "SELECT TS.id FROM {$tables['testsuites']} TS " .
         "JOIN {$tables['nodes_hierarchy']} NH ON NH.id = TS.id " .
         "WHERE TS.details LIKE '%{$needle}%'";

  $rs = $db->get_recordset($sql);
  if (is_null($rs)) {
    return 0;
  }

  foreach ($rs as $row) {
    $sid = intval($row['id']);
    if ($treeMgr->getTreeRoot($sid) == $tprojectId) {
      return $sid;
    }
  }

  return 0;
}


function ws_find_reqspec_id_by_docid_raw(&$db, $docId, $tprojectId)
{
  $tables = tlObjectWithDB::getDBTables(array('req_specs'));
  $safeDocId = $db->prepare_string($docId);

  $sql = "SELECT id FROM {$tables['req_specs']} " .
         "WHERE testproject_id=" . intval($tprojectId) . " " .
         "AND doc_id='{$safeDocId}'";

  $rs = $db->get_recordset($sql);
  if (is_null($rs) || count($rs) == 0) {
    return 0;
  }

  return intval($rs[0]['id']);
}


function ws_ensure_reqspec_has_revision(&$reqSpecMgr, $specId, $spec, $userId, &$report)
{
  $tables = tlObjectWithDB::getDBTables(array('req_specs_revisions'));
  $sql = "SELECT id FROM {$tables['req_specs_revisions']} WHERE parent_id=" . intval($specId) . " LIMIT 1";
  $rs = $reqSpecMgr->db->get_recordset($sql);

  if (!is_null($rs) && count($rs) > 0) {
    return true;
  }

  $revItem = array(
    'revision' => 1,
    'doc_id' => $spec->specKey,
    'name' => $spec->title,
    'scope' => $spec->scope,
    'type' => TL_REQ_SPEC_TYPE_USER_REQ_SPEC,
    'status' => 1,
    'total_req' => count($spec->requirements),
    'author_id' => $userId,
    'log_message' => 'Workspace import recovery: missing initial requirement specification revision'
  );

  $op = $reqSpecMgr->create_revision($specId, $revItem);
  if ($op['status_ok']) {
    ws_add_issue($report, 'RQ3-015', 'WARN', '/requirements/' . $spec->specKey,
                 'Recovered requirement_spec without revision from previous failed import.');
    return true;
  }

  ws_add_issue($report, 'RQ3-011', 'ERROR', '/requirements/' . $spec->specKey,
               'Failed to recover requirement_spec revision: ' . $op['msg']);
  return false;
}


function ws_find_testcase_by_key(&$db, &$treeMgr, $caseKey, $tprojectId)
{
  $tables = tlObjectWithDB::getDBTables(array('tcversions','nodes_hierarchy'));
  $needle = $db->prepare_string('TLWS_CASE_KEY:' . $caseKey);

  $sql = "SELECT NH.parent_id AS testcase_id, TCV.id AS tcversion_id, TCV.version " .
         "FROM {$tables['tcversions']} TCV " .
         "JOIN {$tables['nodes_hierarchy']} NH ON NH.id = TCV.id " .
         "WHERE TCV.preconditions LIKE '%{$needle}%' " .
         "ORDER BY TCV.version DESC";

  $rs = $db->get_recordset($sql);
  if (is_null($rs)) {
    return null;
  }

  foreach ($rs as $row) {
    $tcId = intval($row['testcase_id']);
    if ($treeMgr->getTreeRoot($tcId) == $tprojectId) {
      return $row;
    }
  }

  return null;
}


function ws_find_testcase_by_name_under_suite(&$db, $nodeTypes, $suiteId, $name)
{
  $tables = tlObjectWithDB::getDBTables(array('nodes_hierarchy'));
  $safeName = $db->prepare_string($name);
  $tcNodeType = intval($nodeTypes['testcase']);

  $sql = "SELECT id FROM {$tables['nodes_hierarchy']} " .
         "WHERE parent_id=" . intval($suiteId) .
         " AND node_type_id={$tcNodeType} " .
         " AND name='{$safeName}'";

  $rs = $db->get_recordset($sql);
  if (is_null($rs) || count($rs) == 0) {
    return 0;
  }

  return intval($rs[0]['id']);
}


function ws_req_status_valid($status)
{
  static $allowed = array(
    TL_REQ_STATUS_VALID,
    TL_REQ_STATUS_NOT_TESTABLE,
    TL_REQ_STATUS_DRAFT,
    TL_REQ_STATUS_REVIEW,
    TL_REQ_STATUS_REWORK,
    TL_REQ_STATUS_FINISH,
    TL_REQ_STATUS_IMPLEMENTED,
    TL_REQ_STATUS_OBSOLETE
  );
  return in_array($status, $allowed, true);
}


function ws_req_type_valid($type)
{
  // Requirement type constants are string numbers in TestLink (e.g. '1').
  return is_numeric($type) && intval($type) > 0;
}


function ws_local_name($node)
{
  $name = $node->getName();
  $parts = explode(':', $name);
  return end($parts);
}


function ws_attr($node, $name)
{
  $attrs = $node->attributes();
  if (isset($attrs[$name])) {
    return (string)$attrs[$name];
  }

  foreach ($node->getNamespaces(true) as $ns) {
    $nsAttrs = $node->attributes($ns);
    if (isset($nsAttrs[$name])) {
      return (string)$nsAttrs[$name];
    }
  }

  return '';
}


function ws_first_child($node, $localName)
{
  $set = $node->xpath("./*[local-name()='" . $localName . "']");
  return (is_array($set) && count($set) > 0) ? $set[0] : null;
}


function ws_children($node, $localName)
{
  $set = $node->xpath("./*[local-name()='" . $localName . "']");
  return is_array($set) ? $set : array();
}


function ws_child_text($node, $localName, $default='')
{
  $child = ws_first_child($node, $localName);
  return is_null($child) ? $default : (string)$child;
}


function ws_append_html_marker($text, $label, $key)
{
  $safe = trim((string)$text);
  $marker = '<!-- ' . $label . ':' . $key . ' -->';
  if (strpos($safe, $marker) !== false) {
    return $safe;
  }

  if ($safe === '') {
    return $marker;
  }

  return $safe . "\n" . $marker;
}


function ws_new_report($mode, $tprojectId)
{
  $report = new stdClass();
  $report->jobId = uniqid('wsimp_', true);
  $report->mode = $mode;
  $report->tproject_id = intval($tprojectId);
  $report->schemaVersion = null;
  $report->isValid = true;
  $report->status = 'failed';
  $report->issues = array();

  $report->totalsBySeverity = array(
    'ERROR' => 0,
    'WARN' => 0,
    'INFO' => 0,
  );

  $report->metrics = new stdClass();
  $report->metrics->requirementsDetected = 0;
  $report->metrics->suitesDetected = 0;
  $report->metrics->testcasesDetected = 0;
  $report->metrics->traceLinksDetected = 0;

  $report->metrics->requirementSpecsCreated = 0;
  $report->metrics->requirementSpecsUpdated = 0;
  $report->metrics->requirementsCreated = 0;
  $report->metrics->requirementsUpdated = 0;
  $report->metrics->suitesCreated = 0;
  $report->metrics->suitesUpdated = 0;
  $report->metrics->testcasesCreated = 0;
  $report->metrics->testcasesUpdated = 0;
  $report->metrics->traceabilityLinked = 0;
  $report->metrics->traceabilityFailed = 0;

  return $report;
}


function ws_add_issue(&$report, $code, $severity, $path, $message)
{
  $issue = array(
    'code' => $code,
    'severity' => $severity,
    'path' => $path,
    'message' => $message
  );
  $report->issues[] = $issue;

  if (!isset($report->totalsBySeverity[$severity])) {
    $report->totalsBySeverity[$severity] = 0;
  }
  $report->totalsBySeverity[$severity]++;

  if ($severity === 'ERROR') {
    $report->isValid = false;
  }
}


function ws_render_page($args, $report)
{
  $baseHref = isset($_SESSION['basehref']) ? $_SESSION['basehref'] : '';
  $self = $baseHref . 'lib/workspace/workspaceImport.php';
  $maxKB = intval(config_get('import_file_max_size_bytes') / 1024);

  echo '<!doctype html><html><head><meta charset="utf-8">';
  echo '<title>Workspace XML Import</title>';
  echo '<style>body{font-family:Arial,Helvetica,sans-serif;margin:20px;}';
  echo 'fieldset{max-width:900px;padding:12px;}';
  echo 'label{display:block;margin-top:8px;}';
  echo 'input[type=file],select{margin-top:4px;}';
  echo 'button{margin-top:12px;padding:8px 14px;}';
  echo 'pre{background:#f7f7f7;border:1px solid #ddd;padding:12px;overflow:auto;max-width:1100px;}';
  echo '.hint{color:#444;font-size:0.95em;}</style></head><body>';

  echo '<h2>Workspace XML Import (v1.0)</h2>';
  echo '<p class="hint">Project: <strong>' . htmlspecialchars($args->tproject_name) . '</strong> (ID ' . intval($args->tproject_id) . ')</p>';
  echo '<p class="hint">Supported scope: requirements + testsuites/testcases + requirement links.</p>';

  echo '<form method="post" enctype="multipart/form-data" action="' . htmlspecialchars($self) . '">';
  echo '<fieldset>';
  echo '<legend>Upload XML</legend>';
  echo '<label>XML file</label>';
  echo '<input type="file" name="uploadedFile" accept=".xml,text/xml,application/xml" required>';

  echo '<label>Mode</label>';
  echo '<select name="mode">';
  echo '<option value="dry-run"' . ($args->mode === 'dry-run' ? ' selected' : '') . '>dry-run (validate only)</option>';
  echo '<option value="execute"' . ($args->mode === 'execute' ? ' selected' : '') . '>execute (persist changes)</option>';
  echo '</select>';

  echo '<p class="hint">Max file size: ' . $maxKB . ' KB</p>';
  echo '<button type="submit" name="UploadFile" value="1">Run Import</button>';
  echo '</fieldset>';
  echo '</form>';

  if (!is_null($report)) {
    echo '<h3>Import Report</h3>';
    echo '<pre>' . htmlspecialchars(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . '</pre>';
  }

  echo '</body></html>';
}
