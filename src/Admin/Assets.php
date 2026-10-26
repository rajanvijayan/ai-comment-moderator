<?php
namespace AICommentModerator\Admin;

defined( 'ABSPATH' ) || exit;

class Assets {

	public static function enqueue( string $hook ): void {
		$screens = [ 'settings_page_' . SettingsPage::SLUG, 'edit-comments.php', 'comments_page_' . Dashboard::SLUG ];
		if ( ! in_array( $hook, $screens, true ) ) {
			return;
		}
		wp_enqueue_style( 'aicm-admin', AICM_URL . 'assets/admin.css', [], AICM_VERSION );
		wp_enqueue_script( 'aicm-admin', AICM_URL . 'assets/admin.js', [], AICM_VERSION, true );
		wp_localize_script(
			'aicm-admin',
			'AICM',
			[
				'ajax'  => admin_url( 'admin-ajax.php' ),
				'nonce' => wp_create_nonce( 'aicm_admin' ),
				'i18n'  => [
					'testing'   => __( 'Testing…', 'ai-comment-moderator' ),
					'working'   => __( 'Working…', 'ai-comment-moderator' ),
					'sent'      => __( 'Reply published.', 'ai-comment-moderator' ),
					'discarded' => __( 'Draft discarded.', 'ai-comment-moderator' ),
					'error'     => __( 'Something went wrong.', 'ai-comment-moderator' ),
					'confirm'   => __( 'Publish this reply now?', 'ai-comment-moderator' ),
					'scanDone'  => __( 'Done. %d comments analysed.', 'ai-comment-moderator' ),
					'save'      => __( 'Save & send', 'ai-comment-moderator' ),
					'cancel'    => __( 'Cancel', 'ai-comment-moderator' ),
				],
			]
		);
	}
}
