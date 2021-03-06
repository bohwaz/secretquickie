{include file="_head.tpl"}

<form method="post" action="{$config.APP_URL}?get={$secret_key}">

{$token_js|raw}

<section class="modal">
	<input id="modal_error" type="checkbox" />
	<span class="overlay"></span>
	<article>
		<header>
			<h2><span class="label error">Error:</span> This secret has expired or has already been viewed.</span></h2>
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
			<input type="password" name="password" class="password" />
			<h3 id="wrong_password" class="hidden"><span class="label error">Wrong password or corrupt secret.</span></h3>
		</section>
		<footer>
			<button class="decrypt">Decrypt secret</button>
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
			<button class="confirm">View secret</button>
		</footer>
	</article>
</section>

<section class="modal">
	<input id="modal_secret" type="checkbox" />
	<span class="overlay"></span>
	<article class="md" id="md_secret">
		<header>
			<h3>Here is the secret:</h3>
		</header>
		<section class="content">
			<textarea readonly="readonly" id="secret" cols="70" rows="12"></textarea>
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