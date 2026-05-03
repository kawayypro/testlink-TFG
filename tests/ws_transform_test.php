<?php
// Test helper for ws_transform_xmi_to_tl_workspace
define('TL_WORKSPACE_IMPORT_TEST_MODE', true);
require_once __DIR__ . '/../lib/workspace/workspaceImport.php';

$xmi_str = <<<XML
<XMI>
  <Model>
    <UseCase xmi:id="UC1" name="RF-01">
      <TaggedValue tag="documentation" value="Desc RF-01"/>
    </UseCase>

    <Test xmi:id="T1" name="Test 1"/>

    <Dependency client="T1" supplier="UC1">
      <TaggedValue tag="ea_sourceType" value="Test"/>
    </Dependency>

    <Diagram xmi:id="D1" name="0 BasicPath" diagramType="ActivityDiagram">
      <TaggedValue tag="parent" value="T1"/>
    </Diagram>
  </Model>
</XMI>
XML;

$xmi = @simplexml_load_string($xmi_str);
$report = ws_new_report('transform', 0);
$generated = ws_transform_xmi_to_tl_workspace($xmi, $report);

// Output generated XML and a short summary
echo "--- GENERATED XML ---\n";
echo $generated . "\n";

$gdoc = new DOMDocument();
$gdoc->loadXML($generated);
$reqs = $gdoc->getElementsByTagName('requirement')->length;
$ts = $gdoc->getElementsByTagName('testsuite')->length;
$tc = $gdoc->getElementsByTagName('testcase')->length;
$rlinks = $gdoc->getElementsByTagName('requirement_ref')->length;

echo "requirements=$reqs testsuites=$ts testcases=$tc requirement_refs=$rlinks\n";
