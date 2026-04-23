<?php

$csl = App\Models\LeaveType::find(3);
if($csl) {
    $csl->update([
        "name" => "CSL (Casual & Sick Leave)", 
        "allow_encashment" => true, 
        "allow_carry_forward" => true, 
        "carry_forward_limit" => 6
    ]);
}

$mdl = App\Models\LeaveType::find(2);
if($mdl) {
    $mdl->update([
        "name" => "MDL (Mandatory December Leave)", 
        "allow_encashment" => false, 
        "allow_carry_forward" => false
    ]);
}

$com = App\Models\LeaveType::find(1);
if($com) {
    $com->update([
        "name" => "Comp-off", 
        "allow_encashment" => false, 
        "allow_carry_forward" => false
    ]);
}

App\Models\LeaveType::whereNotIn("id", [1,2,3])->delete();
echo "Leave Types synced!\n";
