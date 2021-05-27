
    @foreach ($attributes as $attr)

    /**
     * {{ $attr['description'] }}
     *
     * @return \Illuminate\Http\Response
     */
    public function {{ $attr['action'] }}({{ $attr['methodParam'] }})
    {{'{'}}
        @if(!empty($attr['request']))
if ($request->fail())
        {{'{'}}
            return response()->json(['code' => 422, 'message' => 'Invalid Params.', 'errors' => $request->errors()], 422);
        {{'}'}}
        @endif

        @if(!empty($attr['model']))
$model = \App\Models\{{ $attr['model'] }};
        $validated = $request->validated();
        @if(in_array($attr['method'], ['put','patch']))
$record = $model::where('id', {{ end($attr['actionParam']) }})->first();
        if (empty($record)) {{'{'}}
            return response()->json(['code' => '404', 'message' => 'Data not found.'], 404);
        {{'}'}} else {{'{'}}
            try {{'{'}}
                $record->update($validated);
            {{'}'}} catch (\Exception $e) {{'{'}}
                return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
            {{'}'}}
            return response()->json($record, 200);
        {{'}'}}
        @elseif($attr['method'] == 'delete')
$record = $model::where('id',  {{ end($attr['actionParam']) }})->first();
        if (empty($record)) {{'{'}}
            return response()->json(['code' => '404', 'message' => 'Data not found.'], 404);
        {{'}'}} else {{'{'}}
            try {{'{'}}
                $record->delete();
            {{'}'}} catch (\Exception $e) {{'{'}}
                return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
            {{'}'}}
            return response()->json($record, 200);
        {{'}'}}
        @elseif($attr['method'] == 'post')
try {{'{'}}
            $record = $model::create($validated);
            return response()->json($record, 201);
        {{'}'}} catch (\Exception $e) {{'{'}}
            return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
        {{'}'}}
        @endif 
    @endif{{'}'}}
    @endforeach