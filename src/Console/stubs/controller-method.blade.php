
    @foreach ($attributes as $attr)

    /**
     * {{ $attr['description'] }}
     *
     * @return \Illuminate\Http\Response
     */
    public function {{ $attr['action'] }}({{ $attr['methodParam'] }})
    {{'{'}}
        @if(!empty($attr['request']))
            $validated = $request->validated();
        @endif

        @if($attr['action'] == 'show')
        $record = {{ $attr['model'] }}::find({{ end($attr['actionParam']) }});
        if (empty($record)) {{'{'}}
            return response()->json(['code' => '404', 'message' => 'Data not found.'], 404);
        {{'}'}}        
        return response()->json($record, 200);
        @elseif(in_array($attr['method'], ['put','patch']))
            $record = {{ $attr['model'] }}::where('id', {{ end($attr['actionParam']) }})->first();
        if (empty($record)) {{'{'}}
            return response()->json(['code' => '404', 'message' => 'Data not found.'], 404);
        {{'}'}} else {{'{'}}
            try {{'{'}}
                $record->fill($validated);
                $record->save();
            {{'}'}} catch (\Exception $e) {{'{'}}
                return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
            {{'}'}}
            return response()->json($record, 200);
        {{'}'}}
        @elseif($attr['method'] == 'delete')
            $record = {{ $attr['model'] }}::where('id', {{ end($attr['actionParam']) }})->first();
        if (empty($record)) {{'{'}}
            return response()->json(['code' => '404', 'message' => 'Data not found.'], 404);
        {{'}'}} else {{'{'}}
            try {{'{'}}
                $record->delete();
            {{'}'}} catch (\Exception $e) {{'{'}}
                return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
            {{'}'}}
            return response('', 202);
        {{'}'}}
        @elseif($attr['method'] == 'post')
        try {{'{'}}
            $record = {{ $attr['model'] }}::create($validated);
            return response()->json($record, 201);
        {{'}'}} catch (\Exception $e) {{'{'}}
            return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
        {{'}'}}
        @endif 
    {{'}'}}
    @endforeach