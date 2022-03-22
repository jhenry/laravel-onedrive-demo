@extends('layout')

@section('content')
<h1>Migration</h1>
@if(isset($result))
    @if($result)
        <div class="alert alert-success" role="alert">
        <p>Success! File has been uploaded to <a href="{{$webUrl}}">{{$webUrl}}</a> </p>
        </div>
    @endif
@endif
<h2>Videos Available For Migration</h2>
<a class="btn btn-light btn-sm mb-3" href={{route('migrate.all')}}>Migrate All Files</a>
<table class="table">
    <thead>
        <tr>
            <th scope="col">File Name</th>
            <th scope="col">Size</th>
            <th scope="col">Action</th>
        </tr>
    </thead>
    <tbody>
        @isset($files)
        @foreach($files as $file)
        <tr>
            <td>{{ $file }}</td>
            <td></td>
            <td>
                <form method="POST" action={{route('migrate.one')}}>
                    @csrf
                    <input type="hidden" class="form-control" name="filePath" value="{{ $file }}" />
                    <input type="submit" class="btn btn-primary mr-2" value="Migrate" />
                </form>
            </td>

        </tr>
        @endforeach
        @endif

    </tbody>
</table>
@isset($oneDriveFiles)
<table class="table">
    <thead>
        <tr>
            <th scope="col">File Name</th>
            <th scope="col">Size</th>
            <th scope="col">Action</th>
        </tr>
    </thead>
    <tbody>

        @foreach($oneDriveFiles as $doc)
        <tr>
            <td>{{ $doc }}</td>
            <td></td>
            <td>

            </td>

        </tr>
        @endforeach

    </tbody>
</table>
@endif
@endsection
