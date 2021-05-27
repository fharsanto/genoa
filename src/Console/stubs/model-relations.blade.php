    @foreach ($relations as $relName => $relation) 
    public function {{ $relName }}()
    {{'{'}}
        return $this->{{ $relation['type'] }}({{ $relation['class'] }}::class);
    {{'}'}}
    @endforeach