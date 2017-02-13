{include file="_head.tpl"}

<form method="post" action="{$config.APP_URL}?get={$secret_key}">

{$token_js|raw}

<section class="modal">
	<input id="modal_error" type="checkbox" />
	<label for="modal_error" class="overlay"></label>
	<article>
		<header>
			<h2><span class="label error">Error:</span> This secret has expired or has already been viewed.</span></h2>
		</header>
	</article>
</section>

<section class="modal">
	<input id="modal_wait" type="checkbox" />
	<label for="modal_wait" class="overlay"></label>
	<article>
		<header>
			<h3>Loading…</h3>
		</header>
	</article>
</section>

<section class="modal">
	<input id="modal_password" type="checkbox" />
	<label for="modal_password" class="overlay"></label>
	<article>
		<header>
			<h3>Enter password to see this secret:</h3>
		</header>
		<section class="content">
			<h3 id="wrong_password" style="display: none;"><span class="label error">Wrong password or corrupt secret.</span></h3>
			<input type="password" name="password" id="password" />
		</section>
		<footer>
			<a class="button" onclick="secretQuickie(); return false;">Decrypt secret</a>
		</footer>
	</article>
</section>

<section class="modal">
	<input id="modal_confirm" type="checkbox" />
	<label for="modal_confirm" class="overlay"></label>
	<article>
		<header>
			<h3>Click to continue:</h3>
		</header>
		<section class="content">
			<p>Careful: the secret will only be displayed once.</p>
		</section>
		<footer>
			<a class="button" onclick="secretQuickie(); return false;">View secret</a>
		</footer>
	</article>
</section>

<section class="modal">
	<input id="modal_view" type="checkbox" />
	<label for="modal_view" class="overlay"></label>
	<article>
		<header>
			<h3>Here is the secret:</h3>
		</header>
		<section class="content">
			<textarea readonly="readonly" id="secret" cols="70" rows="15"></textarea>
		</section>
		<footer>
			<p><span class="label">This secret has been deleted from our server.</span></p>
		</footer>
	</article>
</section>

</form>

<noscript>
	<form method="post" action="{$config.APP_URL}?{$secret_key}">

	<section class="modal">
		<input id="modal_password_nojs" type="checkbox" checked="checked" />
		<label for="modal_password_nojs" class="overlay"></label>
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

<script type="text/javascript" src="{$config.APP_URL}static/sodium.min.js" integrity="sha384-{$js_hashes['sodium.min.js']}"></script>
<script type="text/javascript" src="{$config.APP_URL}static/view.js" integrity="sha384-{$js_hashes['view.js']}"></script>