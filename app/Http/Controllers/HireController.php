<?php

namespace App\Http\Controllers;

use App\Models\Hire;
use App\Models\Tanker;
use App\Models\Company;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use App\Http\Requests\HireStoreRequest;
use App\Http\Requests\HireUpdateRequest;
use Webpatser\Uuid\Uuid;

class HireController extends Controller
{

    public function current_week_range(&$start_date, &$end_date) {
        $current=time();
        $today = date("Y-m-d",$current);

        $ts = strtotime($today);

        $start = (date('w', $ts) == 0) ? $ts : strtotime('last sunday', $ts);

        $start_date = date('Y-m-d', $start);
        $end_date = date('Y-m-d', strtotime('next saturday', $start));
    }


    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function index(Request $request)
    {
        $this->authorize('view-any', Hire::class);


        $title = 'TCL Tanker Track';
        $search = $request->get('search', '');        
        
        $hires = Hire::join('companies', 'companies.id', '=', 'hires.company_id')
        ->join('tankers', 'tankers.id', '=', 'hires.tanker_id')        
        ->where(function ($query)use ($search) {
            $query->orWhere('hires.order_num', 'like', '%'.$search.'%')                    
                  ->orWhere('hires.start_date', 'like', '%'.$search.'%')
                  ->orWhere('hires.end_date', 'like', '%'.$search.'%')
                  ->orWhere('companies.company', 'like', '%'.$search.'%')
                  ->orWhere('tankers.fleet_num', 'like', '%'.$search.'%');
        })
        // ->get(['hires.*', 'companies.company', 'tankers.fleet_num']);        
        // ->latest()
        ->select('hires.*')
        ->orderByDesc('hires.updated_at')
        ->paginate(20);
        
        $status = $request->status;
        if ($status) {
            $this->current_week_range($start_date, $end_date);
            switch ($status) {
                case 'active' :
                    $hires = Hire::where(function($query) use($start_date, $end_date){
                        $query->where("start_date", '>=', $start_date)->where('start_date', '<=', $end_date);
                    })->orWhere(function ($query) use ($start_date, $end_date) {
                        $query->where("end_date", '>=', $start_date)->where('end_date', '<=', $end_date);
                    })->search($search)
                    ->latest()
                    ->paginate(20);
                    break;
                case 'pending':
                    $title = "Pending Contracts";
                    $hires = Hire::where('status', 'pending')
                    ->search($search)
                    ->latest()
                    ->paginate(20);
                    break;
                case 'signed':
                    $hires = Hire::where('status', 'signed')
                    ->search($search)
                    ->latest()
                    ->paginate(20);
                    break;
                case 'onHire':
                    $hires = Hire::where('status', 'onHire')
                    ->search($search)
                    ->latest()
                    ->paginate(20);
                    break;
            }
        }
        return view('app.hires.index', ['hires'=>$hires, 'search' => $search, 'title' => $title]);
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @return \Illuminate\Http\Response
     */
    public function create(Request $request)
    {
        $this->authorize('create', Hire::class);

        $companies = Company::all();
        $tankers = Tanker::where('usage', '=', false)->where('archive', '=', false)->select(['id','fleet_num'])->get();

        return view('app.hires.create', compact('companies', 'tankers'));
    }

    /**
     * @param \App\Http\Requests\HireStoreRequest $request
     * @return \Illuminate\Http\Response
     */
    public function store(HireStoreRequest $request)
    {
        $this->authorize('create', Hire::class);
        $validated = $request->validated();
        $validated['uuid'] = Str::uuid();
        $sel_company = Company::where('company', '=' , $request->company_id)->first();
        $validated['company_id'] = $sel_company->id;

        $hire = Hire::create($validated);        
        $hire->attached_doc = $request->document_path;
        $hire->save();

        $tanker = Tanker::find($hire->tanker_id);
        $tanker->usage = true;
        $tanker->save();

        if($request->hirer_signature == "/img/sign.png" && $request->tcl_signature == "/img/sign.png") {
            $hire->status = "draft";
        }
        else if($request->hirer_signature == "/img/sign.png" && $request->tcl_signature != "/img/sign.png") {
            $hire->status = "pending";
        }
        else if($request->hirer_signature != "/img/sign.png" && $request->tcl_signature != "/img/sign.png") {
            $hire->status = "signed";
        }
        $hire->update($validated);
        return redirect()
            ->route('hires.edit', $hire)
            ->withSuccess(__('crud.common.created'));
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Hire $hire
     * @return \Illuminate\Http\Response
     */
    public function show(Request $request, Hire $hire)
    {
        $this->authorize('view', $hire);

        return view('app.hires.show', compact('hire'));
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Hire $hire
     * @return \Illuminate\Http\Response
     */
    public function edit(Request $request, Hire $hire)
    {
        $this->authorize('update', $hire);
        $companies = Company::all();
        $tankers = Tanker::where('usage', '=', false)->where('archive', '=', false)->select(['id','fleet_num'])->get();
        $cnt_tankers = count($tankers);
        $tankers[$cnt_tankers] = $hire->tanker;
        return view('app.hires.edit', compact('hire', 'companies', 'tankers'));
    }

    /**
     * @param \App\Http\Requests\HireUpdateRequest $request
     * @param \App\Models\Hire $hire
     * @return \Illuminate\Http\Response
     */
    public function update(HireUpdateRequest $request, Hire $hire)
    {
        $this->authorize('update', $hire);

        $validated = $request->validated();
        $sel_company = Company::where('company', '=' , $request->company_id)->first();
        $validated['company_id'] = $sel_company->id;
        if($request->hirer_signature == "/img/sign.png" && $request->tcl_signature == "/img/sign.png") {
            $hire->status = "draft";
        }
        else if($request->hirer_signature == "/img/sign.png" && $request->tcl_signature != "/img/sign.png") {
            $hire->status = "pending";
        }
        else if($request->hirer_signature != "/img/sign.png" && $request->tcl_signature != "/img/sign.png" && $hire->status == 'pending') {
            $hire->status = "signed";
        }
        $old_attached_doc = $hire->attached_doc;
        $hire->update($validated);
        $hire->attached_doc = $request->document_path;
        $hire->save();
        if($hire->status == "signed" && $hire->attached_doc != $old_attached_doc){
            $attached_docs_url = explode(";", $hire->attached_doc);
            $details = [
                'hirer_name' => $hire->hirer_name,
                'link_url' => route('contract_link', ['uuid' => $hire->uuid]),
                'attached_docs_url' => $attached_docs_url,
                'isUpdatedDocs' => "true",
            ];
        
            $email_address = $hire->company->email;
    
            \Mail::to($email_address)->send(new \App\Mail\SendCustomerMail($details));  
        }
        return redirect()
            ->route('hires.edit', $hire)
            ->withSuccess(__('crud.common.saved'));
    }

    /**
     * @param \Illuminate\Http\Request $request
     * @param \App\Models\Hire $hire
     * @return \Illuminate\Http\Response
     */
    public function destroy(Request $request, Hire $hire)
    {
        $this->authorize('delete', $hire);

        $hire->delete();

        return redirect()
            ->route('hires.index')
            ->withSuccess(__('crud.common.removed'));
    }

    public function documentUpload(Request $request)
    {
        $docs = $request->file('document');
        for($i = 0; $i < count($docs); $i ++)
        {
            $filename = time()."__".$i.'.'.$docs[$i]->guessExtension();
            $path = $docs[$i]->storeAs('public/documents',$filename);
            $dest[$i] = env('APP_URL').'/storage/documents/'.$filename;
        }

        return response()->json([
            'path' => $dest,
            'success' => 'success'
        ], 200);
    }
}
