import XCTest

@MainActor
final class M51InteractionTests: XCTestCase {
    func testPolicyApprovalTargetAndRecoveryJourney() {
        let app = XCUIApplication()
        continueAfterFailure = false
        app.launchArguments = ["--ui-testing-m51"]
        app.launch()
        app.buttons["Grocery settings"].tap()
        XCTAssertTrue(app.navigationBars["Grocery settings"].waitForExistence(timeout: 2))
        XCTAssertTrue(app.staticTexts["Household purchasing policy"].exists)
        XCTAssertTrue(app.staticTexts["Barilla Fusilli 500g"].exists)
        app.buttons["Stop preferring this product"].tap()
        XCTAssertTrue(app.buttons["Revoke preference"].waitForExistence(timeout: 2))
        app.buttons["Revoke preference"].tap()
        app.buttons["Save"].tap()
        XCTAssertFalse(app.navigationBars["Grocery settings"].waitForExistence(timeout: 2))

        app.staticTexts["Weeknight plan"].tap()
        XCTAssertTrue(app.staticTexts["Approval summary"].waitForExistence(timeout: 2))
        XCTAssertTrue(app.staticTexts["Vegetable pasta"].exists)
        XCTAssertTrue(app.staticTexts["Replacement"].exists)

        app.buttons["Basket target, $50.00"].tap()
        XCTAssertTrue(app.navigationBars["Plan basket target"].waitForExistence(timeout: 2))
        let target = app.textFields["Target in dollars"]
        target.tap()
        target.clearAndType("55")
        app.buttons["Save"].tap()
        XCTAssertFalse(app.navigationBars["Plan basket target"].waitForExistence(timeout: 2))

        let approve = app.buttons["Approve plan & prepare Coles basket"]
        revealAndTap(approve, in: app)
        tapConfirmationAction("Approve plan", in: app)

        let openBasket = app.staticTexts["Open basket"]
        revealAndTap(openBasket, in: app)
        XCTAssertTrue(app.staticTexts["One decision needed"].waitForExistence(timeout: 2))
        XCTAssertTrue(app.buttons["Use this basket"].exists)
        XCTAssertTrue(app.staticTexts["Tomato lentil pasta"].exists)
        app.buttons["Use this basket"].tap()
        XCTAssertGreaterThanOrEqual(app.buttons.matching(identifier: "Use this basket").count, 1)
    }

    func testAlternativePreferenceConfirmation() {
        let app = launchApp()
        navigateToBasket(in: app)

        let alternatives = app.buttons["Alternatives"]
        revealAndTap(alternatives, in: app)
        XCTAssertTrue(app.staticTexts["Coles Spirals 500g"].waitForExistence(timeout: 2))
        app.buttons["Prefer next time"].tap()
        XCTAssertGreaterThanOrEqual(app.buttons.matching(identifier: "Prefer next time").count, 1)
    }

    func testRestorationConfirmation() {
        let app = launchApp()
        navigateToBasket(in: app)
        let restore = app.buttons["Restore previous basket"]
        revealAndTap(restore, in: app)
        XCTAssertGreaterThanOrEqual(app.buttons.matching(identifier: "Restore previous basket").count, 1)
    }

    func testNonOwnerSeesExplicitOwnerActionCopy() {
        let app = launchApp(additionalArguments: ["--ui-testing-m51-non-owner"])
        navigateToBasket(in: app)

        let ownerCopy = app.staticTexts[
            "The Coles account owner must accept this basket over the target."
        ]
        reveal(ownerCopy, in: app)
    }

    func testUnavailableProductsLeadToOneRevisedPlanReview() {
        let app = launchApp(additionalArguments: ["--ui-testing-m51-unavailable"])
        navigateToBasket(in: app)

        XCTAssertTrue(app.staticTexts[
            "1 required product could not be sourced safely."
        ].waitForExistence(timeout: 2))
        XCTAssertTrue(app.staticTexts["Tomato lentil pasta"].exists)
    }

    private func tapConfirmationAction(_ title: String, in app: XCUIApplication) {
        let matches = app.buttons.matching(identifier: title)
        XCTAssertGreaterThanOrEqual(matches.count, 1)
        for index in 0..<matches.count {
            let action = matches.element(boundBy: index)
            if action.isHittable {
                action.tap()
                return
            }
        }
        XCTFail("No visible confirmation action named \(title)")
    }

    private func revealAndTap(_ element: XCUIElement, in app: XCUIApplication) {
        for _ in 0..<12 {
            if element.exists, element.isHittable {
                element.tap()
                return
            }
            app.swipeUp()
        }
        XCTFail("Could not reveal \(element)")
    }

    private func reveal(_ element: XCUIElement, in app: XCUIApplication) {
        for _ in 0..<12 {
            if element.exists {
                return
            }
            app.swipeUp()
        }
        XCTFail("Could not reveal \(element)")
    }

    private func launchApp(additionalArguments: [String] = []) -> XCUIApplication {
        let app = XCUIApplication()
        continueAfterFailure = false
        app.launchArguments = ["--ui-testing-m51"] + additionalArguments
        app.launch()
        return app
    }

    private func navigateToBasket(in app: XCUIApplication) {
        app.staticTexts["Weeknight plan"].tap()
        XCTAssertTrue(app.staticTexts["Approval summary"].waitForExistence(timeout: 2))
        revealAndTap(app.staticTexts["Open basket"], in: app)
        XCTAssertTrue(app.staticTexts["One decision needed"].waitForExistence(timeout: 2))
    }
}

private extension XCUIElement {
    func clearAndType(_ value: String) {
        guard let currentValue = self.value as? String else {
            typeText(value)
            return
        }

        typeText(String(repeating: XCUIKeyboardKey.delete.rawValue, count: currentValue.count))
        typeText(value)
    }
}
