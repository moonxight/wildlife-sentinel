<?php
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
// Only roles confirmed in the existing schema. Two requested poacher profiles
// remain pending clarification because this application has no poacher role.
return [
    ['email'=>'demo.admin@example.test','name'=>'Demo Administrator','role'=>'admin','password'=>'DemoOnly!Admin1'],
    ['email'=>'demo.ranger1@example.test','name'=>'Demo Ranger One','role'=>'ranger','password'=>'DemoOnly!Ranger1'],
    ['email'=>'demo.ranger2@example.test','name'=>'Demo Ranger Two','role'=>'ranger','password'=>'DemoOnly!Ranger2'],
    ['email'=>'demo.scout1@example.test','name'=>'Demo Scout One','role'=>'scout','password'=>'DemoOnly!Scout1'],
    ['email'=>'demo.scout2@example.test','name'=>'Demo Scout Two','role'=>'scout','password'=>'DemoOnly!Scout2'],
    ['email'=>'demo.scout3@example.test','name'=>'Demo Scout Three','role'=>'scout','password'=>'DemoOnly!Scout3'],
    ['email'=>'demo.scout4@example.test','name'=>'Demo Scout Four','role'=>'scout','password'=>'DemoOnly!Scout4'],
];
