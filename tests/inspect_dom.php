<?php
$xmi_str = '<XMI><Model><UseCase xmi:id="UC1" name="RF-01"><TaggedValue tag="documentation" value="Desc RF-01"/></UseCase><Test xmi:id="T1" name="Test 1"/><Dependency client="T1" supplier="UC1"><TaggedValue tag="ea_sourceType" value="Test"/></Dependency></Model></XMI>';
$dom = new DOMDocument(); $dom->loadXML($xmi_str);
$xpath = new DOMXPath($dom);
$ucs = $xpath->query("//*[local-name()='UseCase']");
foreach ($ucs as $uc) {
  echo "UseCase node attributes:\n";
  foreach ($uc->attributes as $a) {
    echo " name='" . $a->name . "' local='" . $a->localName . "' value='" . $a->value . "'\n";
  }
}
$deps = $xpath->query("//*[local-name()='Dependency']");
foreach ($deps as $d) {
  echo "Dependency supplier='" . $d->getAttribute('supplier') . "'\n";
}
