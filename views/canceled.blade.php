@include('partials.head_minimal')
<body>
{!! $plugins_body_top !!}
<div class="wrapper">
    <section class="content" style="margin-top: 20px">
        <div class="row">
            <div class="col-lg-4 col-lg-offset-4">
                <div class="box box-success">
                    <div class="box-body">
                        <h3 class="box-title text-center">Your payment was cancelled</h3>
                    </div>
                </div>
            </div>
        </div>
    </section>
</div>
@include('partials.js_minimal')
{!! $plugins_body_bottom !!}
</body>
</html>