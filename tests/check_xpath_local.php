<?php
$xmi = <<<XML
<XMI>
  <Model>
    <UML:UseCase xmi:id="UC1" name="RF-01"/>
  </Model>
</XMI>
XML;

$x = simplexml_load_string($xmi);
$u = $x->xpath("//*[local-name()='UseCase']");
if (is_array($u)) {
  echo "Found: " . count($u) . "\n";
} else {
  echo "No results or error\n";
}
var_dump($u);
