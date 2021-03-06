<h2>Browse Console</h2>

<div class="well well-sm">
	<div style="text-align: center;">
		{include file='search-filter.tpl'}
	</div>
</div>
{$site->adbrowse}
{if $results|@count > 0}
	<form id="nzb_multi_operations_form" action="get">
		<div class="well well-sm">
			<div class="nzb_multi_operations">
				<table width="100%">
					<tr>
						<td width="30%">
							With Selected:
							<div class="btn-group">
								<input type="button" class="nzb_multi_operations_download btn btn-small btn-success"
									   value="Download NZBs"/>
								<input type="button" class="nzb_multi_operations_cart btn btn-small btn-info"
									   value="Send to my Download Basket"/>
								{if isset($sabintegrated) && $sabintegrated !=""}
									<input type="button" class="nzb_multi_operations_sab btn btn-small btn-primary"
										   value="Send to queue"/>
								{/if}
							</div>
							<br>
							View: <strong>Covers</strong> | <a
									href="{$smarty.const.WWW_TOP}/browse?t={$category}">List</a>
							<br/>
						</td>
						<td width="50%">
							<div style="text-align: center;">
								{$pager}
							</div>
						</td>
						<td width="20%">
							<div class="pull-right">
								{if isset($isadmin)}
									Admin:
									<div class="btn-group">
										<input type="button" class="nzb_multi_operations_edit btn btn-small btn-warning"
											   value="Edit"/>
										<input type="button"
											   class="nzb_multi_operations_delete btn btn-small btn-danger"
											   value="Delete"/>
									</div>
									&nbsp;
								{/if}
							</div>
						</td>
					</tr>
				</table>
			</div>
		</div>
		<table style="width:100%;" class="data highlight icons table" id="coverstable">
			<tr>
				<th>
					<input type="checkbox" class="nzb_check_all"/>
				</th>
				<th>title<br/>
					<a title="Sort Descending" href="{$orderbytitle_desc}">
						<i class="fa fa-caret-down"></i>
					</a>
					<a title="Sort Ascending" href="{$orderbytitle_asc}">
						<i class="fa fa-caret-up"></i>
					</a>
				</th>
				<th>platform<br/>
					<a title="Sort Descending" href="{$orderbyplatform_desc}">
						<i class="fa fa-caret-down"></i>
					</a>
					<a title="Sort Ascending" href="{$orderbyplatform_asc}">
						<i class="fa fa-caret-up"></i>
					</a>
				</th>
				<th>genre<br/>
					<a title="Sort Descending" href="{$orderbygenre_desc}">
						<i class="fa fa-caret-down"></i>
					</a>
					<a title="Sort Ascending" href="{$orderbygenre_asc}">
						<i class="fa fa-caret-up"></i>
					</a>
				</th>
				<th>release date<br/>
					<a title="Sort Descending" href="{$orderbyreleasedate_desc}">
						<i class="fa fa-caret-down"></i>
					</a>
					<a title="Sort Ascending" href="{$orderbyreleasedate_asc}">
						<i class="fa fa-caret-up"></i>
					</a>
				</th>
				<th>posted<br/>
					<a title="Sort Descending" href="{$orderbyposted_desc}">
						<i class="fa fa-caret-down"></i>
					</a>
					<a title="Sort Ascending" href="{$orderbyposted_asc}">
						<i class="fa fa-caret-up"></i>
					</a>
				</th>
				<th>size<br/>
					<a title="Sort Descending" href="{$orderbysize_desc}">
						<i class="fa fa-caret-down"></i>
					</a>
					<a title="Sort Ascending" href="{$orderbysize_asc}">
						<i class="fa fa-caret-up"></i>
					</a>
				</th>
				<th>files<br/>
					<a title="Sort Descending" href="{$orderbyfiles_desc}">
						<i class="fa fa-caret-down"></i>
					</a>
					<a title="Sort Ascending" href="{$orderbyfiles_asc}">
						<i class="fa fa-caret-up"></i>
					</a>
				</th>
				<th>stats<br/>
					<a title="Sort Descending" href="{$orderbystats_desc}">
						<i class="fa fa-caret-down"></i>
					</a>
					<a title="Sort Ascending" href="{$orderbystats_asc}">
						<i class="fa fa-caret-up"></i>
					</a>
				</th>
			</tr>
			{foreach $results as $result}
				<tr class="{cycle values=",alt"}">
					<td class="mid">
						<div class="movcover">
							<div style="text-align: center;">
									<img class="shadow img img-polaroid"
										 src="{$smarty.const.WWW_TOP}/covers/console/{if isset($result.cover) && $result.cover == 1}{$result.consoleinfo_id}.jpg{else}no-cover.jpg{/if}"
										 width="120" border="0" alt="{$result.title|escape:"htmlall"}"/>
							</div>
							<div style="text-align: center;">
								{if $result.url != ""}
									<a class="rndbtn badge badge-amaz" target="_blank"
									   href="{$site->dereferrer_link}{$result.url}"
									   name="amazon{$result.consoleinfo_id}" title="View amazon page">Amazon</a>
								{/if}
							</div>
						</div>
					</td>
					<td colspan="3" class="left" id="guid{$mguid[$m@index]}">
						<h4>{$result.title|escape:"htmlall"} - {$result.platform|escape:"htmlall"}</h4>
						{if !empty($result.genre)}<b>Genre:</b>{$result.genre}<br/>{/if}
						{if !empty($result.esrb)}<b>Rating:</b>{$result.esrb}<br/>{/if}
						{if !empty($result.publisher)}<b>Publisher:</b>{$result.publisher}<br/>{/if}
						{if !empty($result.releasedate)}<b>Released:</b>{$result.releasedate|date_format}<br/>{/if}
						{if !empty($result.review)}<b>Review:</b>{$result.review|escape:'htmlall'}<br/>{/if}
						<br/>
						<div class="movextra">
							<table class="table" style="margin-bottom:0px; margin-top:10px">
								{assign var="msplits" value=","|explode:$result.grp_release_id}
								{assign var="mguid" value=","|explode:$result.grp_release_guid}
								{assign var="mnfo" value=","|explode:$result.grp_release_nfoid}
								{assign var="mgrp" value=","|explode:$result.grp_release_grpname}
								{assign var="mname" value="#"|explode:$result.grp_release_name}
								{assign var="mpostdate" value=","|explode:$result.grp_release_postdate}
								{assign var="msize" value=","|explode:$result.grp_release_size}
								{assign var="mtotalparts" value=","|explode:$result.grp_release_totalparts}
								{assign var="mcomments" value=","|explode:$result.grp_release_comments}
								{assign var="mgrabs" value=","|explode:$result.grp_release_grabs}
								{assign var="mfailed" value=","|explode:$result.grp_release_failed}
								{assign var="mpass" value=","|explode:$result.grp_release_password}
								{assign var="minnerfiles" value=","|explode:$result.grp_rarinnerfilecount}
								{assign var="mhaspreview" value=","|explode:$result.grp_haspreview}
								{foreach $msplits as $m}
									<tr id="guid{$mguid[$m@index]}" {if $m@index > 0} class="mlextra"{/if}>
										<td>
											<div class="icon"><input type="checkbox" class="nzb_check"
																	 value="{$mguid[$m@index]}"/></div>
										</td>
										<td>
											<a href="{$smarty.const.WWW_TOP}/details/{$mguid[$m@index]}">
												&nbsp;{$mname[$m@index]|escape:"htmlall"}</a>
											<a class="rndbtn btn btn-mini btn-info"
											   href="{$smarty.const.WWW_TOP}/console?platform={$result.platform}"
											   title="View similar nzbs">Similar</a>
											{if isset($isadmin)}
												<a class="rndbtn btn btn-mini btn-warning"
												   href="{$smarty.const.WWW_TOP}/admin/release-edit.php?id={$result.releases_id}&amp;from={$smarty.server.REQUEST_URI|escape:"url"}"
												   title="Edit Release">Edit</a>
												<a class="rndbtn confirm_action btn btn-mini btn-danger"
												   href="{$smarty.const.WWW_TOP}/admin/release-delete.php?id={$result.releases_id}&amp;from={$smarty.server.REQUEST_URI|escape:"url"}"
												   title="Delete Release">Delete</a>
											{/if}
											<br/>
											<ul class="inline">
												<li><b>Info:</b></li>
												<li>Posted {$mpostdate[$m@index]|timeago}</li>
												<li>{{$msize[$m@index]}|fsize_format:"MB"}</li>
												<li><a title="View file list"
													   href="{$smarty.const.WWW_TOP}/filelist/{$mguid[$m@index]}">{$mtotalparts[$m@index]}</a><i
															class="fa fa-file"></i></li>
												<li><a title="View comments for {$result.title|escape:"htmlall"}"
													   href="{$smarty.const.WWW_TOP}/details/{$mguid[$m@index]}/#comments">{$mcomments[$m@index]}</a>
													<i class="fa fa-comments-o"></i></li>
												<li>{$mgrabs[$m@index]} <i class="fa fa-cloud-download"></i></li>
												{if {$mnfo[$m@index]} > 0}
													<a href="{$smarty.const.WWW_TOP}/nfo/{$mguid[$m@index]}" title="View Nfo"
													   class="rndbtn modal_nfo badge" rel="nfo">Nfo</a>
												{/if}
												<a class="rndbtn badge"
												   href="{$smarty.const.WWW_TOP}/browse?g={$mgrp[$m@index]}"
												   title="Browse releases in {$mgrp[$m@index]|replace:"alt.binaries":"a.b"}">Grp</a>
											</ul>
										</td>
										<td class="icons" style="width: 100px">
											<ul class="inline">
												<li style="vertical-align:text-bottom;">
													<a class="icon icon_nzb fa fa-cloud-download"
													   style="text-decoration: none; color: #7ab800;"
													   title="Download Nzb"
													   href="{$smarty.const.WWW_TOP}/getnzb/{$mguid[$m@index]}">
													</a>
												</li>
												<li style="vertical-align:text-bottom;">
													<div>
														<a href="#" class="icon icon_cart fa fa-shopping-basket"
														   style="text-decoration: none; color: #5c5c5c;"
														   title="Send to my Download Basket">
														</a>
													</div>
												</li>
												<li style="vertical-align:text-bottom;">
													{if isset($sabintegrated) && $sabintegrated !=""}
														<div>
															<a href="#" class="icon icon_sab fa fa-share"
															   style="text-decoration: none; color: #008ab8;"
															   title="Send to my Queue">
															</a>
														</div>
													{/if}
												</li>
												<li style="vertical-align:text-bottom;">
													{if $weHasVortex}
														<div>
															<a href="#" class="icon icon_nzb fa fa-cloud-downloadvortex"
															   title="Send to my NZBVortex"><img
																		src="{$smarty.const.WWW_ASSETS}/images/icons/vortex/bigsmile.png"></a>
														</div>
													{/if}
												</li>
											</ul>
										</td>
									</tr>
									{if $m@index == 1 && $m@total > 2}
										<tr>
											<td colspan="5">
												<a class="mlmore" href="#">{$m@total-1} more...</a>
											</td>
										</tr>
									{/if}
								{/foreach}
							</table>
						</div>
					</td>
				</tr>
			{/foreach}
		</table>
		<br/>
		{$pager}
		{if $results|@count > 10}
			<div class="well well-sm">
				<div class="nzb_multi_operations">
					<table width="100%">
						<tr>
							<td width="30%">
								With Selected:
								<div class="btn-group">
									<input type="button" class="nzb_multi_operations_download btn btn-small btn-success"
										   value="Download NZBs"/>
									<input type="button" class="nzb_multi_operations_cart btn btn-small btn-info"
										   value="Send to my Download Basket"/>
									{if isset($sabintegrated) && $sabintegrated !=""}
										<input type="button" class="nzb_multi_operations_sab btn btn-small btn-primary"
											   value="Send to queue"/>
									{/if}
								</div>
								View: <strong>Covers</strong> | <a
										href="{$smarty.const.WWW_TOP}/browse?t={$category}">List</a><br/>
							</td>
							<td width="50%">
								<div style="text-align: center;">
									{$pager}
								</div>
							</td>
							<td width="20%">
								{if isset($section) && $section != ''}
									<div class="pull-right">
										{if isset($isadmin)}
											Admin:
											<div class="btn-group">
												<input type="button"
													   class="nzb_multi_operations_edit btn btn-small btn-warning"
													   value="Edit"/>
												<input type="button"
													   class="nzb_multi_operations_delete btn btn-small btn-danger"
													   value="Delete"/>
											</div>
											&nbsp;
										{/if}
										&nbsp;
									</div>
								{/if}
							</td>
						</tr>
					</table>
				</div>
			</div>
		{/if}
	</form>
{else}
	<div class="alert">
		<button type="button" class="close" data-dismiss="alert">&times;</button>
		<strong>Sorry!</strong> Either some amazon key is wrong, or there is nothing in this section.
	</div>
{/if}
