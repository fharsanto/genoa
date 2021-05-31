
    @foreach ($attributes as $attr)

    /**
     * {{ $attr['description'] }}
     *
     * @return \Illuminate\Http\Response
     */
    public function {{ $attr['action'] }}({{ $attr['methodParam'] }})
    {
        @if(!empty($attr['request']) && $attr['action'] !== 'index')
            $validated = $request->validated();
        @endif

        @if($attr['action'] == 'show')
        $record = {{ $attr['model'] }}::find({{ end($attr['actionParam']) }});
        if (empty($record)) {
            return response()->json(['code' => '404', 'message' => 'Data not found.'], 404);
        }
        return response()->json($record, 200);
        @elseif($attr['action'] == 'index')
        $this->validate($request, [
            'offset' => 'nullable|integer',
            'limit' => 'nullable|integer',
            'filter' => 'nullable|array',
            'order' => 'nullable|string'
        ]);

        $offset = !empty($request['offset']) ? $request['offset'] : 0;
        $limit = !empty($request['limit']) ? $request['limit'] : 10;
        $filter = !empty($request['filter']) ? $request['filter'] : [];
        $order = !empty($request['order']) ? $request['order'] : false;
        $total = {{ $attr['model'] }}::where($filter)->count();

        $headers = [
            'Pagination-Rows' => $total,
            'Pagination-Page' => ceil($total/$limit),
            'Pagination-Limit' => $limit
        ];

        $records = {{ $attr['model'] }}::where($filter)
            ->when($order, function($query, $order){
                return $query->orderBy($order);
            })
            ->offset($offset)
            ->limit($limit)
            ->get();
        
        return response()->json($records, empty($records) ? 404 : 200, $headers);
        @elseif(in_array($attr['method'], ['put','patch']))
            $record = {{ $attr['model'] }}::find({{ end($attr['actionParam']) }});
        if (empty($record)) {
            return response()->json(['code' => '404', 'message' => 'Data not found.'], 404);
        } else {
            try {
                $record->fill($validated);
                $record->save();
            } catch (\Exception $e) {
                return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
            }
            return response()->json($record, 200);
        }
        @elseif($attr['method'] == 'delete')
            $record = {{ $attr['model'] }}::find({{ end($attr['actionParam']) }});
        if (empty($record)) {
            return response()->json(['code' => '404', 'message' => 'Data not found.'], 404);
        } else {
            try {
                $record->delete();
            } catch (\Exception $e) {
                return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
            }
            return response('', 202);
        }
        @elseif($attr['method'] == 'post')
        try {
            $record = {{ $attr['model'] }}::create($validated);
            return response()->json($record, 201);
        } catch (\Exception $e) {
            return response()->json(['code' => '500', 'message' => $e->getMessage()], 500);
        }
        @endif 
    }
    @endforeach