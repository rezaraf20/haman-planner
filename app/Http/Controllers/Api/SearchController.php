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
        $tasks=Task::query()->where(function($query)use($q){$query->where('title','ilike','%'.$q.'%')->orWhere('description','ilike','%'.$q.'%');})
            ->when($data['status']??null,fn($x,$v)=>$x->where('status',$v))
            ->when($data['area_id']??null,fn($x,$v)=>$x->where('area_id',$v))
            ->orderByDesc('importance')->limit(50)->get();
        return response()->json(['query'=>$q,'tasks'=>$tasks]);
    }
}
