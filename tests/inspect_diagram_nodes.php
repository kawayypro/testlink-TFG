<?php
$xmi_str = <<<XML
<XMI>
  <Model>
    <UseCase xmi:id="UC1" name="RF-01"/>
    <ActionState xmi:id="A1" name="Paso 1"/>
    <ActionState xmi:id="A2" name="Paso 2"/>
    <Diagram xmi:id="D1" name="0 BasicPath" diagramType="ActivityDiagram">
      <TaggedValue tag="parent" value="T1"/>
      <DiagramElement subject="A1"/>
      <DiagramElement subject="A2"/>
    </Diagram>
  </Model>
</XMI>
XML;

$dom = new DOMDocument();
$dom->loadXML($xmi_str);
$xpath = new DOMXPath($dom);
$diag = $xpath->query("//*[local-name()='Diagram']")->item(0);
echo "diag children: " . $diag->childNodes->length . "\n";
$all = $xpath->query(".//*", $diag);
echo "descendants: " . $all->length . "\n";
foreach ($all as $node) {
  echo $node->nodeName . " local=" . $node->localName . " subject=" . $node->getAttribute('subject') . " name=" . $node->getAttribute('name') . "\n";
}
