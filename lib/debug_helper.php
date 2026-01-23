<?php
function debug_render($data)
{
    echo '<div class="alert alert-warning p-3 m-3" style="z-index:9999; position:relative;">';
    echo '<pre class="m-0" style="max-height: 200px; overflow:auto;">';
    print_r($data);
    echo '</pre></div>';
}
?>