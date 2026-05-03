<?php
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

    <ActionState xmi:id="A1" name="Abrir pantalla de login e introducir usuario y password validos."/>
    <ActionState xmi:id="A2" name="Hacer click en Entrar."/>

    <Diagram xmi:id="D1" name="0 BasicPath" diagramType="ActivityDiagram">
      <TaggedValue tag="parent" value="T1"/>
      <DiagramElement subject="A1"/>
      <DiagramElement subject="A2"/>
    </Diagram>
  </Model>
</XMI>
XML;

$xmi = @simplexml_load_string($xmi_str);
$report = ws_new_report('transform', 0);
$generated = ws_transform_xmi_to_tl_workspace($xmi, $report);

echo $generated . "\n";
$gdoc = new DOMDocument();
$gdoc->loadXML($generated);
$steps = $gdoc->getElementsByTagName('step')->length;
$actions = $gdoc->getElementsByTagName('actions')->length;
$refs = $gdoc->getElementsByTagName('requirement_ref')->length;
echo "steps=$steps actions=$actions requirement_refs=$refs\n";
