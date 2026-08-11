#if DEBUG
import ChefAPI
import Foundation

struct UITestFixture {
    let session: SessionResponse
    let workspace: MealPlanWorkspace
    let purchasePolicy: RetailerPurchasePolicyEnvelope
    let basket: BasketView

    static func load(arguments: [String]) -> UITestFixture {
        let decoder = JSONDecoder()
        decoder.keyDecodingStrategy = .convertFromSnakeCase
        var basket = basketJSON

        if arguments.contains("--ui-testing-m51-non-owner") {
            basket = basket
                .replacingOccurrences(of: "\"owned_by_current_user\":true", with: "\"owned_by_current_user\":false")
                .replacingOccurrences(of: "\"override_budget\":true", with: "\"override_budget\":false")
        }

        if arguments.contains("--ui-testing-m51-unavailable") {
            basket = basket
                .replacingOccurrences(
                    of: "\"kind\":\"budget_overrun\",\"budget_target_cents\":5000,\"selected_subtotal_cents\":6200,\"blocked_requirement_count\":null",
                    with: "\"kind\":\"product_unavailable\",\"budget_target_cents\":null,\"selected_subtotal_cents\":null,\"blocked_requirement_count\":1"
                )
                .replacingOccurrences(
                    of: "\"kind\":\"budget_overrun\",\"status\":\"pending\"",
                    with: "\"kind\":\"product_unavailable\",\"status\":\"pending\""
                )
        }

        return UITestFixture(
            session: decode(sessionJSON, with: decoder),
            workspace: decode(workspaceJSON, with: decoder),
            purchasePolicy: decode(policyJSON, with: decoder),
            basket: decode(basket, with: decoder)
        )
    }

    private static func decode<Value: Decodable>(
        _ json: String,
        with decoder: JSONDecoder
    ) -> Value {
        do {
            return try decoder.decode(Value.self, from: Data(json.utf8))
        } catch {
            preconditionFailure("Invalid native UI test fixture: \(error)")
        }
    }

    private static let sessionJSON = #"""
    {
      "user":{"id":1,"name":"Alex","email":"alex@example.test"},
      "household":{"id":1,"name":"The Taylors","timezone":"Australia/Melbourne","people":[{"id":1,"name":"Alex"},{"id":2,"name":"Sam"}]},
      "plans":[{"id":10,"title":"Weeknight plan","starts_on":"2026-08-03","ends_on":"2026-08-09","revision":4,"planning_confirmed_at":"2026-08-01T05:00:00Z"}],
      "notifications":{"unread_count":1,"items":[]}
    }
    """#

    private static let workspaceJSON = #"""
    {
      "plan":{"id":10,"title":"Weeknight plan","starts_on":"2026-08-03","ends_on":"2026-08-09","revision":4,"planning_confirmed_at":"2026-08-01T05:00:00Z"},
      "approval_brief":{
        "plan_id":10,"plan_revision":4,"meal_count":1,"estimated_minutes":30,"estimated_cost_cents":2400,
        "meals":[{"meal_slot_id":21,"date":"2026-08-04","kind":"dinner","label":"Tuesday dinner","title":"Vegetable pasta","summary":"A quick pasta dinner","estimated_minutes":30,"estimated_cost_cents":2400,"is_replacement":true,"has_conflicting_proposals":false,"participants":[{"person_id":1,"name":"Alex","servings":1},{"person_id":2,"name":"Sam","servings":1}],"total_servings":2,"participant_default":{"origin":"provisional_history","provisional":true,"source_meal_slot_id":18,"source_label":"Last Tuesday dinner"}}],
        "safety":{"constraints":[{"id":7,"kind":"allergy","subject":"Peanuts","details":"Avoid peanuts","severity":"high","person":"Sam","explicitly_confirmed_at":"2026-08-01T04:00:00Z"}],"inferred":false},
        "purchase_policy":{"provider":"coles","home_brand_preference":"prefer","bulk_preference":"avoid","organic_preference":"no_preference","preferred_brands":["Barilla"],"basket_target_cents":5000,"basket_target_source":"plan"},
        "grocery_preparation":{"provider":"coles","has_standing_consent":true,"approval_will_replace_basket":true,"effect":"Approval prepares recipes and replaces the Coles basket. Checkout remains in Coles."}
      },
      "grocery_preparation":{"enabled":true,"provider":"coles","approval_label":"Approve plan & prepare Coles basket","has_standing_consent":true,"connection":{"id":5,"status":"verified","owned_by_current_user":true},"can_connect":false,"run":{"id":30,"status":"needs_plan_review","public_state":"plan_review_required","public_outcome":null,"attention_kind":"budget_overrun","failure_message":null,"chef_subtotal_cents":6200,"retailer_total_cents":null,"captured_at":null,"polling":false},"consent":null}
    }
    """#

    private static let policyJSON = #"""
    {
      "policy":{"provider":"coles","home_brand_preference":"prefer","bulk_preference":"avoid","organic_preference":"no_preference","preferred_brands":["Barilla"],"default_basket_target_cents":6000,"fingerprint":"policy-fixture"},
      "product_preferences":[{"id":41,"ingredient":"pasta","form":"spirals","sku":"123456","product_title":"Barilla Fusilli 500g","created_at":"2026-08-01T05:00:00Z"}],
      "can":{"update":true}
    }
    """#

    private static let basketJSON = #"""
    {
      "id":30,"status":"needs_plan_review","status_label":"Plan review required","public_state":"plan_review_required","public_outcome":null,"confirmed":false,"uncertain":false,"failure_code":null,"failure_message":null,
      "attention":{"kind":"budget_overrun","budget_target_cents":5000,"selected_subtotal_cents":6200,"blocked_requirement_count":null},
      "plan":{"id":10,"title":"Weeknight plan","starts_on":"2026-08-03","ends_on":"2026-08-09"},
      "connection":{"id":5,"provider":"coles","status":"verified","owned_by_current_user":true,"last_verified_at":"2026-08-01T05:00:00Z"},
      "grocery_plan":{"id":20,"version":1,"status":"selected","built_at":"2026-08-01T05:00:00Z"},
      "effective_policy":{"provider":"coles","home_brand_preference":"prefer","bulk_preference":"avoid","organic_preference":"no_preference","preferred_brands":["Barilla"],"basket_target_cents":5000,"fingerprint":"policy-fixture"},
      "adjustment":{"id":8,"kind":"budget_overrun","status":"pending","generated_at":"2026-08-01T05:00:00Z","items":[{"id":9,"meal_slot_id":21,"date":"2026-08-04","kind":"dinner","previous_title":"Vegetable pasta","replacement_title":"Tomato lentil pasta","replacement_summary":"Keeps the same quick dinner shape at a lower estimated cost.","estimated_minutes":30,"estimated_cost_cents":1800,"proposal_id":77,"proposal_status":"pending"}]},
      "requirements":[{"id":60,"name":"spiral pasta","status":"selected","quantity":500,"unit":"g","quantity_unknown":false}],
      "items":[{"id":70,"requirement":{"id":60,"name":"spiral pasta","quantity":500,"unit":"g","quantity_unknown":false},"product":{"sku":"123456","title":"Barilla Fusilli 500g","brand":"Barilla","pack_quantity":500,"pack_unit":"g","absolute_quantity":1,"unit_price_cents":400,"line_price_cents":400},"reasoning":"Best semantic match with low waste.","low_confidence":false,"semantic_tier":1,"policy_decisions":["preferred_brand"],"policy_exceptions":["budget_target_exceeded"],"alternatives":[{"candidate_id":71,"sku":"654321","title":"Coles Spirals 500g","brand":"Coles","pack_quantity":500,"pack_unit":"g","pack_count":1,"captured_price_cents":250,"total_price_cents":250,"captured_at":"2026-08-01T05:00:00Z","policy_comparison":"Cheaper home-brand option with the same pack size."}],"can_prefer":true,"verified_at":"2026-08-01T05:00:00Z","sources":[{"planned_meal_id":80,"meal_title":"Vegetable pasta","recipe_version_id":81,"recipe_title":"Vegetable pasta","recipe_version":1,"ingredient_name":"spiral pasta","scaled_quantity":500,"unit":"g"}]}],
      "totals":{"chef_subtotal_cents":6200,"retailer_total_cents":null,"price_notice":"Prices remain estimates until checkout."},
      "replaced_line_count":0,"previous_line_count":2,"captured_at":null,
      "can":{"retry":false,"restore":true,"review":true,"override_budget":true}
    }
    """#
}
#endif
