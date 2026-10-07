wp.blocks.registerBlockType('estandar-pop/popup-picker', {
	title: '🎯 Popup',
	description: 'Selector de popup',
	category: 'widgets',
	icon: 'image',
	attributes: {
		selectedPopupId: { type: 'number', default: 0 },
		displayMode: { type: 'string', default: 'button' },
		buttonText: { type: 'string', default: 'Abrir Popup' },
	},
	edit: (props) => {
		const { attributes, setAttributes } = props;
		return wp.element.createElement('div', { style: { padding: '20px', backgroundColor: '#f5f5f5', borderRadius: '4px', textAlign: 'center' } },
			wp.element.createElement(wp.blockEditor.InspectorControls, {},
				wp.element.createElement(wp.components.PanelBody, { title: 'Configuración', initialOpen: true },
					wp.element.createElement(wp.components.SelectControl, {
						label: '📌 Selecciona Popup',
						value: attributes.selectedPopupId ? attributes.selectedPopupId.toString() : '0',
						options: [
							{ label: '— Elige un popup —', value: '0' },
							...(typeof epBlockData !== 'undefined' && epBlockData.popups ? epBlockData.popups.map(p => ({ label: p.label, value: p.value.toString() })) : [])
						],
						onChange: (v) => setAttributes({ selectedPopupId: parseInt(v) || 0 })
					}),
					wp.element.createElement(wp.components.SelectControl, {
						label: '🎨 Estilo',
						value: attributes.displayMode,
						options: [
							{ label: '🔘 Botón', value: 'button' },
							{ label: '📢 Banner', value: 'banner' }
						],
						onChange: (v) => setAttributes({ displayMode: v })
					}),
					wp.element.createElement(wp.components.TextControl, {
						label: '📝 Texto',
						value: attributes.buttonText,
						onChange: (v) => setAttributes({ buttonText: v })
					})
				)
			),
			wp.element.createElement('div', { style: { padding: '20px', textAlign: 'center' } },
				attributes.selectedPopupId === 0 ?
					wp.element.createElement('p', { style: { color: '#999' } }, '🎯 Selecciona un popup') :
					attributes.displayMode === 'button' ?
						wp.element.createElement('button', { disabled: true, style: { padding: '10px 20px', backgroundColor: '#007bff', color: 'white', border: 'none', borderRadius: '4px' } }, attributes.buttonText) :
						wp.element.createElement('div', { style: { padding: '12px', backgroundColor: '#fff3cd', border: '2px solid #ffc107', borderRadius: '4px' } }, attributes.buttonText)
			)
		);
	},
	save: (props) => {
		const { attributes } = props;
		const { selectedPopupId, displayMode, buttonText } = attributes;

		if (!selectedPopupId) return null;

		const className = 'wp-block-estandar-pop-popup-picker ep-block-' + displayMode;
		const buttonProps = {
			'data-ep-open': selectedPopupId.toString(),
			className: className
		};

		return wp.element.createElement(
			displayMode === 'button' ? 'button' : 'div',
			buttonProps,
			buttonText
		);
	}
});
