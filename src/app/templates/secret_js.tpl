{include file="_head.tpl"}

<form method="post" action="{$config.APP_URL}?get={$secret_key}" onsubmit="return secretQuickie();">

{$token_js|raw}

<section class="modal">
	<input id="modal_error" type="checkbox" />
	<a href="{$config.APP_URL}" class="overlay"></a>
	<article>
		<header>
			<h2><span class="label error">Error:</span> This secret has expired or has already been viewed.</span></h2>
			<a class="close" href="{$config.APP_URL}">&times;</a>
		</header>
	</article>
</section>

<section class="modal">
	<input id="modal_wait" type="checkbox" />
	<span class="overlay"></span>
	<article>
		<header>
			<h3>Loading…</h3>
		</header>
	</article>
</section>

<section class="modal">
	<input id="modal_password" type="checkbox" />
	<span class="overlay"></span>
	<article>
		<header>
			<h3>Enter password to see this secret:</h3>
		</header>
		<section class="content">
			<h3 id="wrong_password"><span class="label error">Wrong password or corrupt secret.</span></h3>
			<input type="password" name="password" id="password" />
		</section>
		<footer>
			<a class="button" onclick="return secretQuickie(); return false;">Decrypt secret</a>
		</footer>
	</article>
</section>

<section class="modal">
	<input id="modal_confirm" type="checkbox" />
	<span class="overlay"></span>
	<article>
		<header>
			<h3>Click to continue:</h3>
		</header>
		<section class="content">
			<p>Careful: the secret will only be displayed once.</p>
		</section>
		<footer>
			<a class="button" onclick="return secretQuickie();">View secret</a>
		</footer>
	</article>
</section>

<section class="modal">
	<input id="modal_secret" type="checkbox" />
	<span class="overlay"></span>
	<article class="md" id="md_secret">
		<header>
			<h3>Here is the secret:</h3>
			<a class="close" href="{$config.APP_URL}">&times;</a>
		</header>
		<section class="content">
			<textarea readonly="readonly" id="secret" cols="70" rows="15"></textarea>
		</section>
		<footer>
			<p class="help">This secret has been deleted from our server.</p>
			<button class="button copy">Copy to clipboard</button>
		</footer>
	</article>
</section>

</form>

<noscript>
	<form method="post" action="{$config.APP_URL}?{$secret_key}">

	<section class="modal">
		<input id="modal_password_nojs" type="checkbox" checked="checked" />
		<span class="overlay"></label>
		<article>
			<header>
				<h3>Enter password to see this secret:</h3>
			</header>
			<section class="content">
				<input type="password" name="password" />
			</section>
			<footer>
				{$token|raw}
				<input type="submit" class="button" value="Decrypt secret" />
			</footer>
		</article>
	</section>

	</form>
</noscript>

<script type="text/javascript" src="{$config.APP_URL}static/sodium.min.js?{$asset_version}" integrity="sha384-{$js_hashes['sodium.min.js']}"></script>
<script type="text/javascript" src="{$config.APP_URL}static/view.js?{$asset_version}" integrity="sha384-{$js_hashes['view.js']}"></script>

{include file="_foot.tpl"}