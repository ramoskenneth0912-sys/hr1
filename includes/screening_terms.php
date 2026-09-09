<?php
/**
 * HR1 Hybrid Screening — Structured Terminology / Related-Concept Layer
 *
 * This is NOT an unbounded synonym dictionary. It is a small, curated set of
 * occupational concepts used by the hybrid screening engine to understand
 * that different wording can describe the same type of work ("Kargador" vs
 * "Warehouse Associate"), while still keeping the evidence requirement high:
 *
 *   - `terms`   : phrases/formulations that directly NAME the concept
 *   - `duties`  : concrete responsibility verbs/phrases that IMPLY the concept
 *   - `cores`   : the smallest unambiguous core words (used for weak evidence)
 *   - `related` : other concepts that merely OVERLAP (supporting evidence only,
 *                 never treated as an automatic 100% match)
 *   - `titles`  : job titles that strongly imply the concept (role detection)
 *
 * Concepts are matched in context and combined with rule-based verification,
 * so a single vague keyword never drives the final score.
 *
 * Extend this file (add a concept or more duties) to teach the engine a new
 * occupation — no other code changes required.
 *
 * @return array<string, array{label:string, terms:list<string>, duties:list<string>, cores:list<string>, related:list<string>, titles:list<string>}>
 */

return [
    'warehouse_operations' => [
        'label' => 'Warehouse Operations',
        'terms' => [
            'warehouse operations', 'warehouse work', 'warehouse handling', 'warehouse assistant',
            'warehouse', 'warehousing', 'stockroom', 'stock room', 'bodega', 'storage area',
            'storeroom', 'sorter', 'distribution center', 'fulfillment', 'compounding areas',
            'stocks inside the warehouse', 'warehouse staff',
        ],
        'duties' => [
            'arranging stocks', 'stocking', 'stocking shelves', 'shelving', 'restocking',
            'picking', 'order picking', 'packing', 'packing orders', 'put away', 'putaway',
            'palletizing', 'palletising', 'receiving goods', 'receiving inventory',
            'organizing shipments', 'inbound', 'outbound', 'stock arrangement', 'slotting',
            'arranging stock', 'stock arrangement in warehouse',
        ],
        'cores' => ['warehouse', 'stock', 'storage'],
        'related' => ['loading_unloading', 'inventory_control', 'material_handling', 'logistics_delivery', 'forklift_operation'],
        'titles' => [
            'warehouse worker', 'warehouse associate', 'warehouse helper', 'warehouse staff',
            'warehouse operator', 'warehouse crew', 'warehouse personnel', 'warehouseman',
            'stock boy', 'stock girl', 'sorter', 'packer', 'warehouse supervisor',
        ],
    ],

    'loading_unloading' => [
        'label' => 'Loading / Unloading',
        'terms' => [
            'loading and unloading', 'loading/unloading', 'loading unloading', 'load and unload',
            'load and unloads', 'loading delivery trucks', 'unloading delivery trucks', 'lu',
            'loading trucks', 'unloading trucks', 'cargo handling', 'carga', 'descarga',
            'stuffing', 'unstuffing', 'container loading',
        ],
        'duties' => [
            'loading', 'unloading', 'hauling', 'transferring boxes', 'lifting cargo',
            'manually loading', 'loading goods', 'unloading goods', 'hand carry', 'carrying boxes',
        ],
        'cores' => ['load', 'unload'],
        'related' => ['material_handling', 'warehouse_operations', 'logistics_delivery'],
        'titles' => ['kargador', 'loader', 'cargo loader', 'truck loader', 'unloader', 'porter'],
    ],

    'inventory_control' => [
        'label' => 'Inventory Control',
        'terms' => [
            'inventory control', 'inventory management', 'stock management', 'stock control',
            'inventory', 'stock levels', 'inventory records', 'cycle count', 'stocktaking',
            'stock take', 'inventory checking', 'checking inventory', 'inventory reconciliation',
            'inventory audit', 'warehouse inventory', 'stock monitoring',
        ],
        'duties' => [
            'monitored stock levels', 'monitoring stock levels', 'maintained inventory records',
            'maintaining inventory records', 'recording inventory', 'tracking inventory',
            'tracked inventory', 'monitoring inventory', 'checked inventory',
            'updating inventory', 'managing inventory', 'monitors stock', 'stock count',
        ],
        'cores' => ['inventory', 'stock'],
        'related' => ['warehouse_operations', 'bookkeeping_accounting'],
        'titles' => ['inventory clerk', 'stock clerk', 'inventory controller', 'stock controller', 'inventory analyst'],
    ],

    'material_handling' => [
        'label' => 'Material Handling',
        'terms' => [
            'material handling', 'materials handler', 'handling goods', 'physical handling',
            'physical handling of goods', 'handling of goods', 'manual handling', 'heavy lifting',
            'lifting', 'moving goods', 'moving materials',
        ],
        'duties' => [
            'lifting', 'carrying', 'moved goods', 'handling materials', 'handled goods',
            'moving boxes', 'moving pallets', 'lifting heavy items', 'carrying goods',
            'transporting goods', 'physically handling',
        ],
        'cores' => ['handling', 'lifting', 'carrying'],
        'related' => ['loading_unloading', 'warehouse_operations'],
        'titles' => ['material handler', 'material handler', 'handler', 'warehouse mover', 'kargador'],
    ],

    'forklift_operation' => [
        'label' => 'Forklift Operation',
        'terms' => [
            'forklift operation', 'forklift operator', 'forklift driving', 'forklift driver',
            'forklift', 'fork lift', 'hi-lo', 'hi lo', 'hilo', 'counterbalance',
            'reach truck', 'powered industrial truck', 'pallet jack',
        ],
        'duties' => [
            'operating forklift', 'operated forklift', 'driving forklift', 'drove forklift',
            'operate forklift', 'handles forklift', 'loading with forklift', 'forklift certified',
        ],
        'cores' => ['forklift'],
        'related' => ['warehouse_operations', 'material_handling', 'loading_unloading'],
        'titles' => ['forklift operator', 'lift truck operator'],
    ],

    'logistics_delivery' => [
        'label' => 'Logistics / Delivery',
        'terms' => [
            'logistics', 'supply chain', 'freight', 'transportation', 'shipping', 'receiving',
            'dispatch', 'dispatching', 'delivery preparation', 'delivery driver', 'route delivery',
            'courier', 'trucking', 'forwarding', 'consolidation', 'container van',
        ],
        'duties' => [
            'coordinating deliveries', 'preparing deliveries', 'assisting with delivery',
            'delivery preparation', 'scheduling shipments', 'manifest', 'waybill',
            'loading for delivery', 'sending shipments', 'receiving shipments',
        ],
        'cores' => ['logistics', 'delivery', 'shipping'],
        'related' => ['warehouse_operations', 'loading_unloading', 'driving_operation'],
        'titles' => [
            'logistics assistant', 'logistics staff', 'delivery helper', 'delivery crew',
            'delivery assistant', 'dispatcher', 'shipping clerk', 'receiving clerk',
        ],
    ],

    'customer_service' => [
        'label' => 'Customer Service',
        'terms' => [
            'customer service', 'customer care', 'client service', 'serving customers',
            'customer support', 'assisting customers', 'guest service', 'guest relations',
        ],
        'duties' => [
            'assisted customers', 'assisting customers', 'attending to customers', 'answering inquiries',
            'handling complaints', 'resolved customer', 'responding to customers', 'customer inquiries',
            'greeted customers', 'taking orders', 'served customers',
        ],
        'cores' => ['customer', 'client', 'guests'],
        'related' => ['sales_retail', 'cashier', 'food_service'],
        'titles' => ['customer service representative', 'customer service', 'service crew', 'marketing assistant', 'receptionist'],
    ],

    'cashier' => [
        'label' => 'Cashier / Point of Sale',
        'terms' => ['cashier', 'point of sale', 'pos', 'cash handling', 'cash register', 'checkout'],
        'duties' => [
            'handled cash', 'cash transactions', 'processing payments', 'operating the cashier',
            'cashiering', 'opening/closing cash', 'handled payments', 'cash drawer', 'charged customers',
        ],
        'cores' => ['cashier', 'cash'],
        'related' => ['customer_service', 'sales_retail', 'bookkeeping_accounting'],
        'titles' => ['cashier', 'checkout clerk'],
    ],

    'sales_retail' => [
        'label' => 'Sales / Retail',
        'terms' => ['sales', 'retailing', 'retail', 'merchandising', 'selling', 'sales clerk', 'retail associate', 'sales representative', 'sales assistant'],
        'duties' => [
            'selling products', 'assisted customers in choosing', 'promoted products', 'sales transactions',
            'merchandise display', 'replenishing shelves', 'meeting sales targets', 'recommending products',
        ],
        'cores' => ['sales', 'merchandise', 'retail'],
        'related' => ['customer_service', 'cashier'],
        'titles' => ['retail associate', 'sales clerk', 'sales assistant', 'sales representative', 'marketing staff'],
    ],

    'clerical_admin' => [
        'label' => 'Clerical / Administrative',
        'terms' => [
            'clerical', 'administrative assistant', 'office assistant', 'administrative',
            'documentation', 'filing', 'data encoding', 'data entry', 'office work', 'secretarial',
        ],
        'duties' => [
            'filing documents', 'encoding data', 'answering phone calls', 'scheduling appointments',
            'processing paperwork', 'preparing reports', 'typing documents', 'maintaining records',
            'office errands', 'assisting the office', 'telephone inquiries',
        ],
        'cores' => ['clerical', 'filing', 'encoding', 'documents'],
        'related' => ['inventory_control', 'bookkeeping_accounting'],
        'titles' => ['administrative assistant', 'office assistant', 'clerical staff', 'data entry clerk', 'encoder', 'secretary'],
    ],

    'bookkeeping_accounting' => [
        'label' => 'Bookkeeping / Accounting',
        'terms' => ['bookkeeping', 'accounting', 'accounts payable', 'accounts receivable', 'billing', 'bookkeeper', 'payroll', 'auditing', 'financial records'],
        'duties' => [
            'recorded transactions', 'managed accounts', 'prepared financial reports', 'processed invoices',
            'reconciled accounts', 'monitoring collections', 'checking documents', 'accounting entries',
        ],
        'cores' => ['accounting', 'bookkeeping', 'billing'],
        'related' => ['clerical_admin', 'cashier'],
        'titles' => ['bookkeeper', 'accounting assistant', 'accounting clerk', 'billing clerk', 'cashier', 'finance assistant'],
    ],

    'food_service' => [
        'label' => 'Food Service / Restaurant',
        'terms' => [
            'food service', 'restaurant', 'server', 'waiter', 'waitress', 'kitchen', 'cook',
            'barista', 'fast food', 'food preparation', 'dining', 'f&b', 'food and beverage',
        ],
        'duties' => [
            'serving food', 'serving customers', 'taking orders', 'preparing food', 'kitchen duties',
            'cleaning tables', 'waiting tables', 'restocking supplies', 'washing dishes', 'customer service in restaurant',
        ],
        'cores' => ['restaurant', 'kitchen', 'serving'],
        'related' => ['customer_service', 'cashier'],
        'titles' => ['waiter', 'waitress', 'server', 'restaurant server', 'service crew', 'kitchen help', 'line cook', 'barista'],
    ],

    'driving_operation' => [
        'label' => 'Driving / Transport',
        'terms' => ['driver', 'driving', 'truck driver', 'licensed driver', 'delivery driver', 'commercial driver', 'driving skills'],
        'duties' => ['drove vehicles', 'driving trucks', 'delivering goods', 'safe driving', 'checking vehicles', 'maintained vehicles'],
        'cores' => ['driver', 'driving'],
        'related' => ['logistics_delivery', 'loading_unloading'],
        'titles' => ['driver', 'truck driver', 'delivery driver', 'van driver', 'company driver'],
    ],

    'cleaning_janitorial' => [
        'label' => 'Cleaning / Janitorial',
        'terms' => ['cleaning', 'janitorial', 'janitor', 'housekeeping', 'sanitation', 'custodian', 'maintenance cleaning'],
        'duties' => ['cleaned offices', 'sweeping', 'mopping', 'disinfecting', 'waste disposal', 'maintained cleanliness'],
        'cores' => ['cleaning', 'clean', 'janitor'],
        'related' => [],
        'titles' => ['janitor', 'cleaner', 'housekeeper', 'utility worker', 'maintenance staff'],
    ],

    'security_guard' => [
        'label' => 'Security',
        'terms' => ['security guard', 'security officer', 'security', 'guard duty', 'crowd control', 'roving guard'],
        'duties' => ['guarding premises', 'checking of visitors', 'monitored security', 'patrolling', 'inspecting bags', 'cctv monitoring'],
        'cores' => ['guard', 'security'],
        'related' => [],
        'titles' => ['security guard', 'security officer', 'roving guard'],
    ],

    'technical_it' => [
        'label' => 'Technical / IT',
        'terms' => ['it support', 'technical support', 'computer', 'networking', 'software', 'hardware', 'programming', 'system administration', 'troubleshooting', 'web development'],
        'duties' => ['repaired computers', 'troubleshooting hardware', 'installed software', 'maintained systems', 'configured networks', 'deployed systems'],
        'cores' => ['software', 'hardware', 'computer'],
        'related' => [],
        'titles' => ['it support', 'technical support', 'computer technician', 'network technician', 'programmer', 'web developer'],
    ],

    'construction' => [
        'label' => 'Construction',
        'terms' => ['construction', 'building', 'carpentry', 'welding', 'masonry', 'site work', 'renovation', 'steel works'],
        'duties' => ['carrying materials', 'assisting construction', 'painting', 'welding', 'formworks', 'demolition', 'site maintenance'],
        'cores' => ['construction', 'site', 'welding'],
        'related' => ['material_handling'],
        'titles' => ['construction worker', 'laborer', 'helper', 'welder', 'carpenter', 'mason'],
    ],

    'healthcare_support' => [
        'label' => 'Healthcare / Caregiving',
        'terms' => ['caregiving', 'caregiver', 'nursing', 'caregiver assistant', 'first aid', 'patient care', 'healthcare'],
        'duties' => ['assisted patients', 'cared for', 'vital signs', 'bathing patients', 'feeding patients', 'administered medicines'],
        'cores' => ['caregiving', 'patient', 'nursing'],
        'related' => [],
        'titles' => ['caregiver', 'nursing aide', 'nurse assistant', 'care assistant'],
    ],
];