import Foundation
import XCTest
@testable import ChefAPI

final class ChefAPIClientTests: XCTestCase {
    override func setUp() {
        super.setUp()
        MockURLProtocol.handler = nil
    }

    func testLoadsSessionAndSendsBearerToken() async throws {
        MockURLProtocol.handler = { request in
            XCTAssertEqual(request.url?.path, "/api/v1/session")
            XCTAssertEqual(request.value(forHTTPHeaderField: "Authorization"), "Bearer test-token")

            return Self.response(
                request,
                status: 200,
                json: """
                {
                  "user": {"id": 1, "name": "Dan", "email": "dan@example.test"},
                  "household": {
                    "id": 3,
                    "name": "Family",
                    "timezone": "Australia/Melbourne",
                    "people": [{"id": 7, "name": "Dan"}]
                  },
                  "plans": [{
                    "id": 9,
                    "title": "Weeknight plan",
                    "starts_on": "2026-07-31",
                    "ends_on": "2026-08-06",
                    "revision": 4,
                    "planning_confirmed_at": null
                  }],
                  "notifications": {
                    "unread_count": 1,
                    "items": [{
                      "id": "notice-1",
                      "title": "Basket ready",
                      "message": "Review it.",
                      "status": "ready",
                      "basket_run_id": 21,
                      "read_at": null,
                      "created_at": "2026-07-31T12:00:00+10:00"
                    }]
                  }
                }
                """
            )
        }

        let session = try await client().loadSession(token: "test-token")

        XCTAssertEqual(session.household.name, "Family")
        XCTAssertEqual(session.plans.first?.id, 9)
        XCTAssertEqual(session.notifications.items.first?.basketRunId, 21)
    }

    func testDecodesDisabledAndActiveGroceryPreparation() async throws {
        MockURLProtocol.handler = { request in
            Self.response(
                request,
                status: 200,
                json: """
                {
                  "workspace": {
                    "plan": {
                      "id": 9,
                      "title": "Weeknight plan",
                      "starts_on": "2026-07-31",
                      "ends_on": "2026-08-06",
                      "revision": 4,
                      "planning_confirmed_at": "2026-07-31T12:00:00+10:00"
                    },
                    "grocery_preparation": {
                      "enabled": true,
                      "provider": "coles",
                      "approval_label": "Approve plan & prepare Coles basket",
                      "has_standing_consent": true,
                      "connection": {
                        "id": 8,
                        "status": "connected",
                        "owned_by_current_user": true
                      },
                      "can_connect": true,
                      "run": {
                        "id": 21,
                        "status": "replacing_basket",
                        "public_state": "preparing",
                        "public_outcome": null,
                        "attention_kind": null,
                        "failure_message": null,
                        "chef_subtotal_cents": null,
                        "retailer_total_cents": null,
                        "captured_at": null,
                        "polling": true
                      },
                      "consent": {
                        "version": "v1",
                        "disclosure": "Disclosure",
                        "links": {
                          "coles_online_safety": "https://example.test/safety",
                          "coles_customer_agreement": "https://example.test/agreement"
                        }
                      }
                    }
                  }
                }
                """
            )
        }

        let response = try await client().loadWorkspace(planID: 9, token: "token")

        XCTAssertEqual(response.workspace.groceryPreparation.run?.status, "replacing_basket")
        XCTAssertEqual(response.workspace.groceryPreparation.run?.publicState, "preparing")
        XCTAssertNil(response.workspace.groceryPreparation.run?.attentionKind)
        XCTAssertTrue(response.workspace.groceryPreparation.run?.polling == true)
        XCTAssertEqual(response.workspace.groceryPreparation.connection?.ownedByCurrentUser, true)
    }

    func testDecodesAdditiveBasketPublicStateAndBudgetAttention() async throws {
        MockURLProtocol.handler = { request in
            Self.response(
                request,
                status: 200,
                json: """
                {
                  "basket": {
                    "id": 21,
                    "status": "needs_plan_review",
                    "status_label": "Revised plan needs review",
                    "public_state": "plan_review_required",
                    "public_outcome": null,
                    "confirmed": false,
                    "uncertain": false,
                    "failure_code": null,
                    "failure_message": null,
                    "attention": {
                      "kind": "budget_overrun",
                      "budget_target_cents": 10000,
                      "selected_subtotal_cents": 11500,
                      "blocked_requirement_count": null
                    },
                    "plan": {
                      "id": 9,
                      "title": "Weeknight plan",
                      "starts_on": "2026-07-31",
                      "ends_on": "2026-08-06"
                    },
                    "connection": null,
                    "grocery_plan": null,
                    "requirements": [],
                    "items": [],
                    "totals": {
                      "chef_subtotal_cents": 11500,
                      "retailer_total_cents": null,
                      "price_notice": "Prices are estimates."
                    },
                    "replaced_line_count": 0,
                    "previous_line_count": 0,
                    "captured_at": null,
                    "can": {
                      "retry": false,
                      "restore": false,
                      "review": false,
                      "override_budget": true
                    }
                  }
                }
                """
            )
        }

        let response = try await client().loadBasket(runID: 21, token: "token")

        XCTAssertEqual(response.basket.publicState, "plan_review_required")
        XCTAssertEqual(response.basket.attention?.kind, "budget_overrun")
        XCTAssertEqual(response.basket.attention?.selectedSubtotalCents, 11_500)
        XCTAssertEqual(response.basket.can.overrideBudget, true)
    }

    func testDecodesApprovalPolicyAlternativesAndAdjustmentAdditively() async throws {
        MockURLProtocol.handler = { request in
            if request.url?.path.contains("workspace") == true {
                return Self.response(
                    request,
                    status: 200,
                    json: Self.workspaceWithApprovalBriefJSON
                )
            }

            return Self.response(
                request,
                status: 200,
                json: Self.basketWithM51DetailsJSON
            )
        }

        let workspace = try await client().loadWorkspace(planID: 9, token: "token").workspace
        let basket = try await client().loadBasket(runID: 21, token: "token").basket

        XCTAssertEqual(workspace.approvalBrief?.meals.first?.title, "Penne primavera")
        XCTAssertEqual(workspace.approvalBrief?.meals.first?.participantDefault.origin, "provisional_history")
        XCTAssertEqual(workspace.approvalBrief?.purchasePolicy.basketTargetCents, 10_000)
        XCTAssertEqual(basket.effectivePolicy?.fingerprint, "policy-fingerprint")
        XCTAssertEqual(basket.adjustment?.items.first?.replacementTitle, "Vegetable pasta")
        XCTAssertEqual(basket.items.first?.semanticTier, 1)
        XCTAssertEqual(basket.items.first?.policyExceptions, ["bulk_preference:unavoidable"])
        XCTAssertEqual(basket.items.first?.alternatives?.first?.candidateId, 88)
        XCTAssertEqual(basket.items.first?.canPrefer, true)
    }

    func testSendsTypedM51PolicyPreferenceAndBudgetMutations() async throws {
        MockURLProtocol.handler = { request in
            let path = request.url?.path ?? ""
            let bodyData = Self.bodyData(request)
            let body = bodyData.isEmpty
                ? nil
                : try JSONSerialization.jsonObject(with: bodyData) as? [String: Any]

            switch path {
            case "/api/v1/retailer-purchase-policy":
                if request.httpMethod == "PUT" {
                    XCTAssertEqual(body?["home_brand_preference"] as? String, "prefer")
                    XCTAssertEqual(body?["preferred_brands"] as? [String], ["Barilla"])
                }
                return Self.response(request, status: 200, json: Self.purchasePolicyJSON)
            case "/api/v1/meal-plans/9/purchase-preference":
                XCTAssertEqual(body?["basket_target_cents"] as? Int, 9_500)
                return Self.response(
                    request,
                    status: 200,
                    json: #"{"purchase_preference":{"id":4,"provider":"coles","basket_target_cents":9500}}"#
                )
            case "/api/v1/basket-runs/21/items/44/product-preference":
                XCTAssertEqual(body?["retailer_product_candidate_id"] as? Int, 88)
                return Self.response(
                    request,
                    status: 200,
                    json: #"{"preference":{"id":5,"sku":"alt-sku","product_title":"Alternative pasta","revoked":false}}"#
                )
            case "/api/v1/retailer-product-preferences/5":
                return Self.response(request, status: 200, json: #"{"preference":{"id":5,"revoked":true}}"#)
            case "/api/v1/basket-runs/21/budget-override":
                return Self.response(
                    request,
                    status: 200,
                    json: #"{"basket_run":{"id":21,"status":"revalidating_products","budget_override_cents":11500,"budget_overridden_at":"2026-08-01T12:00:00+10:00"}}"#
                )
            default:
                XCTFail("Unexpected M5.1 request: \(path)")
                return Self.response(request, status: 404, json: "{}")
            }
        }

        let client = client()
        _ = try await client.loadRetailerPurchasePolicy(token: "token")
        _ = try await client.updateRetailerPurchasePolicy(
            RetailerPurchasePolicyRequest(
                homeBrandPreference: "prefer",
                bulkPreference: "avoid",
                organicPreference: "no_preference",
                preferredBrands: ["Barilla"],
                defaultBasketTargetCents: 10_000
            ),
            token: "token"
        )
        _ = try await client.updateMealPlanPurchasePreference(
            planID: 9,
            basketTargetCents: 9_500,
            token: "token"
        )
        _ = try await client.rememberProductPreference(
            runID: 21,
            itemID: 44,
            candidateID: 88,
            token: "token"
        )
        _ = try await client.revokeProductPreference(preferenceID: 5, token: "token")
        let override = try await client.overrideBasketBudget(runID: 21, token: "token")

        XCTAssertEqual(override.basketRun.budgetOverrideCents, 11_500)
    }

    func testMapsTwoFactorChallenge() async throws {
        MockURLProtocol.handler = { request in
            Self.response(
                request,
                status: 422,
                json: """
                {
                  "message": "Two-factor authentication is required.",
                  "two_factor_required": true
                }
                """
            )
        }

        do {
            _ = try await client().authenticate(
                AuthenticationRequest(
                    email: "dan@example.test",
                    password: "password",
                    deviceName: "Test iPhone"
                )
            )
            XCTFail("Expected a two-factor challenge.")
        } catch let error as ChefAPIError {
            XCTAssertEqual(
                error,
                .twoFactorRequired("Two-factor authentication is required.")
            )
        }
    }

    func testRelaysLiveInputAsEphemeralRequestData() async throws {
        MockURLProtocol.handler = { request in
            XCTAssertEqual(
                request.url?.path,
                "/api/v1/retailer-connections/8/live-input"
            )
            XCTAssertEqual(
                try JSONSerialization.jsonObject(with: Self.bodyData(request)) as? [String: String],
                ["text": "temporary secret"]
            )

            return Self.response(
                request,
                status: 200,
                json: """
                {"forwarded": true, "kind": "text"}
                """
            )
        }

        let response = try await client().relayLiveInput(
            connectionID: 8,
            input: .text("temporary secret"),
            token: "token"
        )

        XCTAssertTrue(response.forwarded)
        XCTAssertEqual(response.kind, "text")
    }

    private func client() -> ChefAPIClient {
        let configuration = URLSessionConfiguration.ephemeral
        configuration.protocolClasses = [MockURLProtocol.self]

        return ChefAPIClient(
            baseURL: URL(string: "https://chef.example.test")!,
            session: URLSession(configuration: configuration)
        )
    }

    private static func response(
        _ request: URLRequest,
        status: Int,
        json: String
    ) -> (HTTPURLResponse, Data) {
        (
            HTTPURLResponse(
                url: request.url!,
                statusCode: status,
                httpVersion: nil,
                headerFields: ["Content-Type": "application/json"]
            )!,
            Data(json.utf8)
        )
    }

    private static func bodyData(_ request: URLRequest) -> Data {
        if let body = request.httpBody {
            return body
        }

        guard let stream = request.httpBodyStream else {
            return Data()
        }

        stream.open()
        defer { stream.close() }

        var data = Data()
        let buffer = UnsafeMutablePointer<UInt8>.allocate(capacity: 1_024)
        defer { buffer.deallocate() }

        while true {
            let count = stream.read(buffer, maxLength: 1_024)
            guard count > 0 else {
                break
            }
            data.append(buffer, count: count)
        }

        return data
    }

    private static let purchasePolicyJSON = """
    {
      "policy": {
        "provider": "coles",
        "home_brand_preference": "prefer",
        "bulk_preference": "avoid",
        "organic_preference": "no_preference",
        "preferred_brands": ["Barilla"],
        "default_basket_target_cents": 10000,
        "fingerprint": "policy-fingerprint"
      },
      "product_preferences": [{
        "id": 5,
        "ingredient": "pasta",
        "form": "penne",
        "sku": "alt-sku",
        "product_title": "Alternative pasta",
        "created_at": "2026-08-01T10:00:00+10:00"
      }],
      "can": {"update": true}
    }
    """

    private static let workspaceWithApprovalBriefJSON = """
    {
      "workspace": {
        "plan": {"id":9,"title":"Weeknight plan","starts_on":"2026-07-31","ends_on":"2026-08-06","revision":4,"planning_confirmed_at":null},
        "approval_brief": {
          "plan_id": 9,
          "plan_revision": 4,
          "meal_count": 1,
          "meals": [{
            "meal_slot_id": 12,
            "date": "2026-08-03",
            "kind": "dinner",
            "label": null,
            "title": "Penne primavera",
            "summary": "Vegetable pasta",
            "estimated_minutes": 30,
            "estimated_cost_cents": 1800,
            "is_replacement": true,
            "has_conflicting_proposals": false,
            "participants": [{"person_id":7,"name":"Dan","servings":1}],
            "total_servings": 1,
            "participant_default": {"origin":"provisional_history","provisional":true,"source_meal_slot_id":2,"source_label":"2026-07-27 dinner"}
          }],
          "estimated_minutes": 30,
          "estimated_cost_cents": 1800,
          "safety": {"constraints": [], "inferred": false},
          "purchase_policy": {"provider":"coles","home_brand_preference":"prefer","bulk_preference":"avoid","organic_preference":"no_preference","preferred_brands":["Barilla"],"basket_target_cents":10000,"basket_target_source":"plan"},
          "grocery_preparation": {"provider":"coles","has_standing_consent":true,"approval_will_replace_basket":true,"effect":"Approval starts basket preparation."}
        },
        "grocery_preparation": {"enabled":true,"provider":"coles","approval_label":"Approve plan & prepare Coles basket","has_standing_consent":true,"connection":null,"can_connect":true,"run":null,"consent":null}
      }
    }
    """

    private static let basketWithM51DetailsJSON = """
    {
      "basket": {
        "id":21,"status":"needs_plan_review","status_label":"Revised plan needs review","public_state":"plan_review_required","public_outcome":null,"confirmed":false,"uncertain":false,"failure_code":null,"failure_message":null,
        "attention":{"kind":"product_unavailable","budget_target_cents":null,"selected_subtotal_cents":null,"blocked_requirement_count":1},
        "plan":{"id":9,"title":"Weeknight plan","starts_on":"2026-07-31","ends_on":"2026-08-06"},
        "connection":{"id":8,"provider":"coles","status":"connected","owned_by_current_user":true,"last_verified_at":"2026-08-01T10:00:00+10:00"},
        "grocery_plan":{"id":3,"version":1,"status":"ready","built_at":"2026-08-01T10:00:00+10:00"},
        "effective_policy":{"provider":"coles","home_brand_preference":"prefer","bulk_preference":"avoid","organic_preference":"no_preference","preferred_brands":["Barilla"],"basket_target_cents":10000,"fingerprint":"policy-fingerprint"},
        "adjustment":{"id":6,"kind":"product_unavailable","status":"ready","generated_at":"2026-08-01T11:00:00+10:00","items":[{"id":7,"meal_slot_id":12,"date":"2026-08-03","kind":"dinner","previous_title":"Penne primavera","replacement_title":"Vegetable pasta","replacement_summary":"Uses available vegetables","estimated_minutes":25,"estimated_cost_cents":1500,"proposal_id":14,"proposal_status":"pending"}]},
        "requirements":[],
        "items":[{"id":44,"requirement":{"id":31,"name":"Pasta","quantity":500,"unit":"g","quantity_unknown":false},"product":{"sku":"selected-sku","title":"Penne 500g","brand":"Coles","pack_quantity":500,"pack_unit":"g","absolute_quantity":1,"unit_price_cents":200,"line_price_cents":200},"reasoning":"Best valid pack.","low_confidence":false,"semantic_tier":1,"policy_decisions":["home_brand_preference:prefer"],"policy_exceptions":["bulk_preference:unavoidable"],"alternatives":[{"candidate_id":88,"sku":"alt-sku","title":"Alternative pasta","brand":"Barilla","pack_quantity":500,"pack_unit":"g","pack_count":1,"captured_price_cents":220,"total_price_cents":220,"captured_at":"2026-08-01T10:00:00+10:00","policy_comparison":"20 cents more."}],"can_prefer":true,"verified_at":null,"sources":[]}],
        "totals":{"chef_subtotal_cents":200,"retailer_total_cents":null,"price_notice":"Prices are estimates."},"replaced_line_count":0,"previous_line_count":0,"captured_at":null,
        "can":{"retry":false,"restore":false,"review":false,"override_budget":false}
      }
    }
    """
}

private final class MockURLProtocol: URLProtocol, @unchecked Sendable {
    nonisolated(unsafe) static var handler: (@Sendable (URLRequest) throws -> (HTTPURLResponse, Data))?

    override class func canInit(with request: URLRequest) -> Bool {
        true
    }

    override class func canonicalRequest(for request: URLRequest) -> URLRequest {
        request
    }

    override func startLoading() {
        guard let handler = Self.handler else {
            XCTFail("MockURLProtocol handler was not configured.")
            return
        }

        do {
            let (response, data) = try handler(request)
            client?.urlProtocol(self, didReceive: response, cacheStoragePolicy: .notAllowed)
            client?.urlProtocol(self, didLoad: data)
            client?.urlProtocolDidFinishLoading(self)
        } catch {
            client?.urlProtocol(self, didFailWithError: error)
        }
    }

    override func stopLoading() {}
}
