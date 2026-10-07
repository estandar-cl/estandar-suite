<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<style>
	.en-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;margin:18px 0 26px}
	.en-card{background:#fff;border:1px solid #dcdcde;border-radius:8px;padding:16px}
	.en-card h2{font-size:14px;margin:0 0 8px;color:#1d2327}
	.en-card p{margin:0;color:#646970;font-size:13px}
	.en-badge{display:inline-block;padding:3px 9px;border-radius:999px;background:#f0f6fc;color:#0969da;font-size:11px;font-weight:700;text-transform:uppercase}
	.en-list{margin:0;padding-left:18px;color:#50575e}
</style>

<div class="notice notice-info inline">
	<p><strong>Notification subsystem initialized.</strong><br>No adapters installed.</p>
</div>

<div class="en-grid">
	<div class="en-card">
		<h2>Notification Engine</h2>
		<p><span class="en-badge"><?php echo esc_html( (string) $status['engine'] ); ?></span></p>
	</div>
	<div class="en-card">
		<h2>Registry</h2>
		<p><span class="en-badge"><?php echo esc_html( (string) $status['registry'] ); ?></span></p>
	</div>
	<div class="en-card">
		<h2>Adapters</h2>
		<p><?php echo esc_html( (string) $status['adapters'] ); ?> registered</p>
	</div>
	<div class="en-card">
		<h2>Health</h2>
		<p>Configured: <?php echo esc_html( (string) $status['configured'] ); ?> · Enabled: <?php echo esc_html( (string) $status['enabled'] ); ?></p>
	</div>
</div>

<div class="en-card">
	<h2>Architecture</h2>
	<p>Leads -> Business Event -> Notification Engine -> Notification Adapters -> Channels.</p>
</div>

<div class="en-card" style="margin-top:14px">
	<h2>Future Channels</h2>
	<ul class="en-list">
		<li>Email</li>
		<li>WhatsApp</li>
		<li>Push</li>
		<li>Telegram</li>
		<li>Slack</li>
		<li>SMS</li>
	</ul>
</div>
