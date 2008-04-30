<div class="wrap"> 
    <h1>Postie</h1>
    <?php if (!current_user_can("config_postie"))  :?>
        <h2>WARNING! - You must be logged in as "admin" to configure Postie.</h2>

    <?php endif;?>
    <p>I'm working on some more detailed instructions, but for now this will have to do.</p>
    <pre>
    <?php include("README");?>
    </pre>
</div>
