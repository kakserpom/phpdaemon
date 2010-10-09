{include file="header.tpl" title="Todo List"}

<h1>Todo List</h1>

{foreach from=$todos key=todopackage item=todo}
<h2>{$todopackage}</h2>

<ul>
	{section name=todo loop=$todo}
	<li>{$todo[todo].link}
		<ul>
			{section name=t loop=$todo[todo].todos}
			<li>{$todo[todo].todos[t]}</li>
			{/section}
		</ul>
	</li>
	{/section}
</ul>
{/foreach}

{include file="footer.tpl"}
