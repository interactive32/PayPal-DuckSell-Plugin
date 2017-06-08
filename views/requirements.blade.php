@extends('app')

@section('content')

    <section>
        <div class="row">
            <div class="col-lg-6 col-lg-offset-3">
                <div class="box box-solid box-warning">
                    <div class="box-header">
                        <h3 class="box-title">Error: Missing Requirement</h3>
                    </div>
                    <div class="box-body">
                        <p>
                           This plugin requires DuckSell 1.5 or greater. Please update your script first.
                        </p>
                    </div>
                </div>
            </div>
        </div>
    </section>

@endsection
