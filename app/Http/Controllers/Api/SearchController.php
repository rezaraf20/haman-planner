<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Task;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class SearchController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $data=$request->validate(['q'=>'required|string|min:2|max:100','status'=>'nullable|string','area_id'=>'nullable|integer']);
        $q=trim($data['q']);
        $like='%'.addcslashes($q,'%_\\').'%';
        $tasks=Task::query()->with(['goal','project','milestone'])->where(function($query)use($like){$query->where('title','ilike',$like)->orWhere('description','ilike',$like);})
            ->when($data['status']??null,fn($x,$v)=>$x->where('status',$v))
            ->when($data['area_id']??null,fn($x,$v)=>$x->where('area_id',$v))
            ->orderByRaw("CASE WHEN status IN ('completed','cancelled') THEN 1 ELSE 0 END")->orderByDesc('importance')->limit(50)->get();
        // Everything else the user wrote (only when no task-specific filter is set).
        $other=fn(string $class,array $cols)=>($data['status']??null)?collect():$class::query()->where(function($w)use($cols,$like){foreach($cols as $c)$w->orWhere($c,'ilike',$like);})->latest('id')->limit(20)->get();
        $goals=$other(\App\Models\Goal::class,['title','description']);
        $projects=$other(\App\Models\Project::class,['title','description']);
        $milestones=$other(\App\Models\Milestone::class,['title']);
        $notes=$other(\App\Models\Note::class,['title','content']);
        $decisions=$other(\App\Models\Decision::class,['title','decision','rationale']);
        return response()->json(['query'=>$q,'tasks'=>$tasks,'goals'=>$goals,'projects'=>$projects,'milestones'=>$milestones,'notes'=>$notes,'decisions'=>$decisions]);
    }
}
