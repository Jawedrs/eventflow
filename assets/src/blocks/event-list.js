import { __ } from '@wordpress/i18n';
import { registerBlockType } from '@wordpress/blocks';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import { PanelBody, RangeControl, SelectControl } from '@wordpress/components';
import ServerSideRender from '@wordpress/server-side-render';
import { useSelect } from '@wordpress/data';

registerBlockType( 'eventflow/event-list', {
	edit: function Edit( { attributes, setAttributes } ) {
		const blockProps = useBlockProps();
		const categories = useSelect(
			( select ) =>
				select( 'core' ).getEntityRecords(
					'taxonomy',
					'ef_event_category',
					{ per_page: 100 }
				),
			[]
		);
		const categoryOptions = [
			{ label: __( 'Every category', 'eventflow' ), value: '' },
			...( categories || [] ).map( ( { name, slug } ) => ( {
				label: name,
				value: slug,
			} ) ),
		];
		return (
			<div { ...blockProps }>
				<InspectorControls>
					<PanelBody
						title={ __( 'Event list settings', 'eventflow' ) }
					>
						<RangeControl
							label={ __( 'Events to show', 'eventflow' ) }
							min={ 1 }
							max={ 24 }
							value={ attributes.count }
							onChange={ ( count ) => setAttributes( { count } ) }
						/>
						<SelectControl
							label={ __( 'Category', 'eventflow' ) }
							value={ attributes.category }
							options={ categoryOptions }
							onChange={ ( category ) =>
								setAttributes( { category } )
							}
						/>
					</PanelBody>
				</InspectorControls>
				<ServerSideRender
					block="eventflow/event-list"
					attributes={ attributes }
				/>
			</div>
		);
	},
	save: () => null,
} );
