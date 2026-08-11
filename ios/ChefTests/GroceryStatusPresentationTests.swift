import XCTest
@testable import Chef

final class GroceryStatusPresentationTests: XCTestCase {
    func testMutationIsPresentedAsActiveAndCalm() {
        let presentation = GroceryStatusPresentation.make(status: "replacing_basket")

        XCTAssertTrue(presentation.isActive)
        XCTAssertFalse(presentation.needsAttention)
        XCTAssertEqual(presentation.title, "Replacing the Coles basket")
    }

    func testUncertainStateRequiresAttentionWithoutClaimingSuccess() {
        let presentation = GroceryStatusPresentation.make(status: "uncertain")

        XCTAssertFalse(presentation.isActive)
        XCTAssertTrue(presentation.needsAttention)
        XCTAssertEqual(presentation.title, "Basket needs review")
    }

    func testReadOnlyDiscoveryFinishesWithoutClaimingBasketMutation() {
        let presentation = GroceryStatusPresentation.make(status: "products_selected")

        XCTAssertFalse(presentation.isActive)
        XCTAssertFalse(presentation.needsAttention)
        XCTAssertEqual(presentation.title, "Products selected; basket unchanged")
    }

    func testAdjustmentDraftingRemainsAQuietActiveState() {
        let presentation = GroceryStatusPresentation.make(status: "preparing_resolution")

        XCTAssertTrue(presentation.isActive)
        XCTAssertFalse(presentation.needsAttention)
        XCTAssertEqual(presentation.title, "Preparing one plan resolution")
    }

    func testRevisedPlanRequiresOneUserDecision() {
        let presentation = GroceryStatusPresentation.make(status: "needs_plan_review")

        XCTAssertFalse(presentation.isActive)
        XCTAssertTrue(presentation.needsAttention)
        XCTAssertEqual(presentation.title, "Revised plan needs review")
    }
}
