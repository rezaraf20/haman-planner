<?php
namespace Database\Seeders;
use Illuminate\Database\Seeder;
use App\Models\FailureReason;
class FailureReasonSeeder extends Seeder {
 public function run(): void {
  $items=[
   ['no_time','No Time','yes',2],['poor_estimation','Poor Estimation','yes',2],['unexpected_work','Unexpected Work','partial',2],
   ['low_energy','Low Energy','partial',2],['distraction','Distraction','yes',2],['too_difficult','Too Difficult','partial',3],
   ['unclear_task','Unclear Task','yes',2],['waiting','Waiting','no',1],['blocked','Blocked','partial',3],
   ['wrong_priority','Wrong Priority','yes',3],['context_switching','Context Switching','yes',2],['personal_issue','Personal Issue','no',2],
   ['technical_problem','Technical Problem','partial',3],['other','Other','partial',1]
  ];
  foreach($items as [$code,$name,$preventable,$severity]) FailureReason::updateOrCreate(['code'=>$code],compact('code','name','preventable','severity'));
 }
}